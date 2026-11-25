# UA Project Manager — Android Tasks (0.1.0)

Початкова Android-версія містить лише задачі. Локальні записи зберігаються у SQLite і доступні без інтернету. WorkManager запускає синхронізацію, коли мережа з'являється.

## Підключення
1. У WordPress створіть **Пароль застосунку** для окремого користувача: Користувачі → Профіль → Паролі застосунків.
2. Користувач має мати право керування задачами UA Project Manager.
3. У застосунку введіть HTTPS-адресу сайту, логін WordPress і пароль застосунку.
4. Не використовуйте звичайний пароль WordPress.

API: `/wp-json/uapm/v1/tasks`. Авторизація — WordPress Application Password over HTTPS.

## Збірка
GitHub Actions workflow `.github/workflows/android-apk.yml` збирає debug APK і публікує його як artifact. Цей APK призначений для тестування, не для Google Play.

## Обмеження тестової версії
- APK поки синхронізує лише задачі.
- Якщо сервер і телефон одночасно змінили одну задачу, API повертає HTTP 409; автоматичного злиття полів ще немає, щоб не перезаписувати дані непомітно.
- Доступ зберігається в Android EncryptedSharedPreferences.


Build verification is performed by GitHub Actions for each Android source update. Install only the APK artifact from a successful run; failed runs do not provide an installable APK.
