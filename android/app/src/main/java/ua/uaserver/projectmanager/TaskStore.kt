package ua.uaserver.projectmanager

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import org.json.JSONObject
import java.util.UUID

data class LocalTask(
    val uuid: String, val title: String, val description: String, val notes: String,
    val executor: String, val status: String, val revision: Int, val pending: Boolean
)

class TaskStore(context: Context) : SQLiteOpenHelper(context, "uapm_tasks.db", null, 1) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("""CREATE TABLE tasks(
            uuid TEXT PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
            notes TEXT NOT NULL DEFAULT '', executor TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending', revision INTEGER NOT NULL DEFAULT 0,
            pending INTEGER NOT NULL DEFAULT 1, deleted INTEGER NOT NULL DEFAULT 0
        )""")
    }
    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {}
    fun all(): List<LocalTask> {
        val out = mutableListOf<LocalTask>()
        readableDatabase.rawQuery("SELECT * FROM tasks WHERE deleted=0 ORDER BY status='completed', rowid DESC", null).use { c ->
            while (c.moveToNext()) out += LocalTask(c.getString(0),c.getString(1),c.getString(2),c.getString(3),c.getString(4),c.getString(5),c.getInt(6),c.getInt(7)==1)
        }
        return out
    }
    fun add(title: String, description: String, notes: String, executor: String) {
        val v = ContentValues().apply {
            put("uuid", UUID.randomUUID().toString()); put("title", title); put("description", description)
            put("notes", notes); put("executor", executor); put("status", "pending"); put("revision", 0); put("pending", 1)
        }
        writableDatabase.insertWithOnConflict("tasks", null, v, SQLiteDatabase.CONFLICT_REPLACE)
    }
    fun setStatus(uuid: String, status: String) {
        writableDatabase.execSQL("UPDATE tasks SET status=?, pending=1 WHERE uuid=?", arrayOf(status, uuid))
    }
    fun pending(): List<JSONObject> {
        val out = mutableListOf<JSONObject>()
        readableDatabase.rawQuery("SELECT * FROM tasks WHERE pending=1 AND deleted=0", null).use { c ->
            while(c.moveToNext()) out += JSONObject().put("uuid",c.getString(0)).put("title",c.getString(1))
                .put("description",c.getString(2)).put("notes",c.getString(3)).put("executor",c.getString(4))
                .put("status",c.getString(5)).put("revision",c.getInt(6))
        }
        return out
    }
    fun markSynced(uuid: String, revision: Int) {
        val v = ContentValues().apply { put("revision", revision); put("pending", 0) }
        writableDatabase.update("tasks", v, "uuid=?", arrayOf(uuid))
    }
    fun mergeServer(obj: JSONObject) {
        val uuid = obj.optString("uuid")
        if (uuid.isBlank()) return
        val local = readableDatabase.rawQuery("SELECT pending FROM tasks WHERE uuid=?", arrayOf(uuid)).use { if(it.moveToFirst()) it.getInt(0)==1 else false }
        if (local) return
        val v = ContentValues().apply {
            put("uuid", uuid); put("title", obj.optString("title")); put("description", obj.optString("description"))
            put("notes", obj.optString("notes")); put("executor", obj.optString("executor"))
            put("status", obj.optString("status","pending")); put("revision", obj.optInt("revision",1)); put("pending",0)
            put("deleted", if(obj.isNull("deleted_at")) 0 else 1)
        }
        writableDatabase.insertWithOnConflict("tasks", null, v, SQLiteDatabase.CONFLICT_REPLACE)
    }
}
