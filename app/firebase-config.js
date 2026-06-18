// Firebase 前端登入設定
// 1. 到 Firebase Console 建立 Web App 後，將 firebaseConfig 改成你的專案設定。
// 2. Authentication > Sign-in method 啟用 Email/Password。
// 3. Authentication > Settings > Authorized domains 加入：
//    - mihsia.github.io
//    - npes.synology.me
//    - localhost
// 注意：這些設定不是密碼；Firebase Web 設定可放在前端，真正的安全性由 Firebase Auth 與授權網域控管。

export const firebaseConfig = {
  apiKey: "REPLACE_WITH_FIREBASE_API_KEY",
  authDomain: "REPLACE_WITH_PROJECT_ID.firebaseapp.com",
  projectId: "REPLACE_WITH_PROJECT_ID",
  appId: "REPLACE_WITH_FIREBASE_APP_ID"
};

export const firebaseAuthOptions = {
  provider: "email-password",
  sessionPersistence: "browserLocal"
};
