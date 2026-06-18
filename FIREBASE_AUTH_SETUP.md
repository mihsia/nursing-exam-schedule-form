# Firebase Authentication 設定

本系統前端登入已改用 Firebase Authentication，不再使用 NAS/PHP 帳密控管。

## 1. 建立 Firebase 專案與 Web App

1. 前往 Firebase Console。
2. 建立或選擇一個專案。
3. 在專案中新增 Web App。
4. 複製 Firebase 提供的 `firebaseConfig`。

## 2. 更新系統設定檔

開啟 `app/firebase-config.js`，將下列欄位改成你的 Firebase Web App 設定：

```js
export const firebaseConfig = {
  apiKey: "你的 apiKey",
  authDomain: "你的 projectId.firebaseapp.com",
  projectId: "你的 projectId",
  appId: "你的 appId"
};
```

## 3. 啟用登入方式

在 Firebase Console：

1. Authentication
2. Sign-in method
3. 啟用 Email/Password
4. 到 Users 建立可登入的使用者帳號

## 4. 設定授權網域

在 Authentication > Settings > Authorized domains 加入實際使用的網域：

- `mihsia.github.io`
- `npes.synology.me`
- `localhost`

請只填網域，不要填 `https://`，也不要填 port。

## 5. 部署注意

- `firebase-config.js` 不是密碼，可以放在前端。
- 真正的登入安全由 Firebase Authentication、授權網域與使用者帳號控管。
- 若尚未填入 Firebase 設定，系統會停在登入畫面並提示更新 `firebase-config.js`。
