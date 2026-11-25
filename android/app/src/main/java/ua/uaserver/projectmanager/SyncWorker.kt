package ua.uaserver.projectmanager

import androidx.work.Worker
import androidx.work.WorkerParameters
import android.content.Context
import java.net.HttpURLConnection
import java.net.URL
import java.util.Base64
import org.json.JSONObject

class SyncWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {
    override fun doWork(): Result {
        val prefs = try { SecurePrefs.get(applicationContext) } catch (_: Exception) { return Result.failure() }
        val site = prefs.getString("site", "")?.trimEnd('/') ?: ""
        val user = prefs.getString("user", "") ?: ""
        val pass = prefs.getString("pass", "") ?: ""
        if (!site.startsWith("https://") || user.isBlank() || pass.isBlank()) return Result.success()
        val auth = "Basic " + Base64.getEncoder().encodeToString("$user:$pass".toByteArray())
        val store = TaskStore(applicationContext)
        return try {
            val get = URL("$site/wp-json/uapm/v1/tasks").openConnection() as HttpURLConnection
            get.requestMethod = "GET"; get.setRequestProperty("Authorization", auth); get.connectTimeout = 15000; get.readTimeout = 20000
            val response = get.inputStream.bufferedReader().readText(); get.disconnect()
            val serverTasks = JSONObject(response).optJSONArray("tasks")
            if (serverTasks != null) for (i in 0 until serverTasks.length()) store.mergeServer(serverTasks.getJSONObject(i))
            for (task in store.pending()) {
                val post = URL("$site/wp-json/uapm/v1/tasks").openConnection() as HttpURLConnection
                post.requestMethod = "POST"; post.doOutput = true; post.setRequestProperty("Authorization", auth)
                post.setRequestProperty("Content-Type", "application/json; charset=utf-8"); post.connectTimeout = 15000; post.readTimeout = 20000
                post.outputStream.use { it.write(task.toString().toByteArray()) }
                val code = post.responseCode
                if (code in 200..299) {
                    val body = post.inputStream.bufferedReader().readText()
                    val saved = JSONObject(body).optJSONObject("task")
                    if (saved != null) store.markSynced(task.getString("uuid"), saved.optInt("revision",1))
                } else if (code == 409) {
                    post.disconnect()
                    return Result.retry()
                } else {
                    post.disconnect()
                    return if (code >= 500) Result.retry() else Result.failure()
                }
                post.disconnect()
            }
            Result.success()
        } catch (_: Exception) { Result.retry() }
    }
}
