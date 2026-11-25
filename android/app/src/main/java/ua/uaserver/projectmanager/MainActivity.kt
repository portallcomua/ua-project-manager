package ua.uaserver.projectmanager

import android.app.Activity
import android.os.Bundle
import android.view.View
import android.widget.*
import androidx.work.*
import java.util.concurrent.TimeUnit

class MainActivity : Activity() {
    private lateinit var store: TaskStore
    private lateinit var list: LinearLayout
    private lateinit var status: TextView
    private lateinit var site: EditText
    private lateinit var username: EditText
    private lateinit var password: EditText

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = TaskStore(this)
        val root = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(20,20,20,20) }
        root.addView(TextView(this).apply { text = "UA Project Manager · Задачі"; textSize = 23f })
        status = TextView(this).apply { text = "Офлайн-режим готовий. Задачі зберігаються на телефоні." }
        root.addView(status)
        site = EditText(this).apply { hint = "Адреса сайту (https://...)" }
        username = EditText(this).apply { hint = "WordPress логін" }
        password = EditText(this).apply { hint = "Пароль застосунку WordPress"; inputType = 129 }
        val prefs = SecurePrefs.get(this)
        site.setText(prefs.getString("site","")); username.setText(prefs.getString("user",""))
        root.addView(site); root.addView(username); root.addView(password)
        root.addView(Button(this).apply { text = "Зберегти доступ і синхронізувати"; setOnClickListener {
            if (!site.text.toString().startsWith("https://")) { status.text = "Потрібна HTTPS-адреса сайту."; return@setOnClickListener }
            prefs.edit().putString("site",site.text.toString()).putString("user",username.text.toString())
                .putString("pass",password.text.toString()).apply()
            scheduleSync(); status.text = "Доступ збережено. Синхронізацію заплановано."; refresh()
        }})
        root.addView(Button(this).apply { text = "Додати задачу"; setOnClickListener { addTask() } })
        root.addView(Button(this).apply { text = "Синхронізувати зараз"; setOnClickListener { scheduleSync(); status.text = "Синхронізація в черзі…" } })
        val scroll = ScrollView(this)
        list = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        scroll.addView(list); root.addView(scroll, LinearLayout.LayoutParams(-1,0,1f))
        setContentView(root)
        refresh()
        scheduleSync()
    }

    private fun addTask() {
        val title = EditText(this).apply { hint = "Назва задачі" }
        val description = EditText(this).apply { hint = "Опис задачі"; minLines = 3 }
        val notes = EditText(this).apply { hint = "Власні нотатки"; minLines = 2 }
        val executor = Spinner(this)
        executor.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item,
            listOf("Google AI","ChatGPT","AI Studio","DeepSeek","GitHub Copilot","Власний виконавець"))
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(24,12,24,12) }
        box.addView(title); box.addView(description); box.addView(notes); box.addView(executor)
        AlertDialogBuilder.show(this, box) {
            val t = title.text.toString().trim()
            if (t.isNotEmpty()) { store.add(t,description.text.toString(),notes.text.toString(),executor.selectedItem.toString()); refresh(); scheduleSync() }
        }
    }

    private fun editTask(task: LocalTask) {
        val title = EditText(this).apply { hint = "Назва задачі"; setText(task.title) }
        val description = EditText(this).apply { hint = "Опис задачі"; minLines = 3; setText(task.description) }
        val notes = EditText(this).apply { hint = "Власні нотатки"; minLines = 2; setText(task.notes) }
        val executor = EditText(this).apply { hint = "Виконавець"; setText(task.executor) }
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(24,12,24,12) }
        box.addView(title); box.addView(description); box.addView(notes); box.addView(executor)
        AlertDialogBuilder.show(this, box) {
            val t = title.text.toString().trim()
            if (t.isNotEmpty()) {
                store.update(task.uuid,t,description.text.toString(),notes.text.toString(),executor.text.toString())
                refresh(); scheduleSync()
            }
        }
    }

    private fun refresh() {
        list.removeAllViews()
        for (task in store.all()) {
            val card = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(8,12,8,12) }
            card.addView(TextView(this).apply { text = task.title; textSize = 18f })
            if (task.executor.isNotBlank()) card.addView(TextView(this).apply { text = "Виконавець: " + task.executor })
            if (task.description.isNotBlank()) card.addView(TextView(this).apply { text = task.description })
            if (task.notes.isNotBlank()) card.addView(TextView(this).apply { text = "Нотатки: " + task.notes })
            val done = CheckBox(this).apply { text = "Виконано"; isChecked = task.status == "completed"; setOnCheckedChangeListener { _, checked ->
                store.setStatus(task.uuid, if(checked) "completed" else "pending"); refresh(); scheduleSync()
            }}
            card.addView(done)
            card.addView(Button(this).apply { text = "Редагувати"; setOnClickListener { editTask(task) } })
            card.addView(TextView(this).apply { text = if(task.pending) "Очікує синхронізації" else "Синхронізовано"; textSize = 12f })
            list.addView(card); list.addView(View(this).apply { setBackgroundColor(0xFFDDDDDD.toInt()) }, LinearLayout.LayoutParams(-1,1))
        }
    }

    private fun scheduleSync() {
        val request = OneTimeWorkRequestBuilder<SyncWorker>().setConstraints(
            Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
        ).setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build()
        WorkManager.getInstance(this).enqueueUniqueWork("uapm-task-sync", ExistingWorkPolicy.KEEP, request)
        val periodic = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
        WorkManager.getInstance(this).enqueueUniquePeriodicWork("uapm-task-periodic", ExistingPeriodicWorkPolicy.KEEP, periodic)
    }
}

object AlertDialogBuilder {
    fun show(activity: Activity, view: View, onSave: () -> Unit) {
        android.app.AlertDialog.Builder(activity).setTitle("Нова задача").setView(view)
            .setNegativeButton("Скасувати", null).setPositiveButton("Зберегти") { _, _ -> onSave() }.show()
    }
}
