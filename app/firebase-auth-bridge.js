import { firebaseAuthOptions, firebaseConfig } from "./firebase-config.js";

const missingConfig = !firebaseConfig?.apiKey
  || firebaseConfig.apiKey.includes("REPLACE_WITH")
  || !firebaseConfig?.authDomain
  || firebaseConfig.authDomain.includes("REPLACE_WITH")
  || !firebaseConfig?.projectId
  || firebaseConfig.projectId.includes("REPLACE_WITH")
  || !firebaseConfig?.appId
  || firebaseConfig.appId.includes("REPLACE_WITH");

const authState = {
  configured: !missingConfig,
  user: null,
  ready: false,
  error: missingConfig ? "尚未設定 Firebase 專案資訊，請先更新 firebase-config.js。" : ""
};

const listeners = new Set();

function emitChange() {
  listeners.forEach((listener) => listener({ ...authState }));
  window.dispatchEvent(new CustomEvent("nursing-exam-auth-change", { detail: { ...authState } }));
}

function friendlyError(error) {
  const code = error?.code || "";
  if (code === "auth/invalid-credential" || code === "auth/wrong-password" || code === "auth/user-not-found") {
    return "電子郵件或密碼不正確。";
  }
  if (code === "auth/invalid-email") return "請輸入有效的電子郵件帳號。";
  if (code === "auth/too-many-requests") return "登入嘗試次數過多，請稍後再試。";
  if (code === "auth/unauthorized-domain") return "此網域尚未加入 Firebase 授權網域。";
  if (code === "auth/network-request-failed") return "目前無法連線 Firebase，請檢查網路後再試。";
  return error?.message || "Firebase 登入失敗，請稍後再試。";
}

const bridge = {
  ready: Promise.resolve(),
  isConfigured: () => authState.configured,
  getStatus: () => ({
    ok: authState.ready && !authState.error,
    configured: authState.configured,
    authenticated: Boolean(authState.user),
    username: authState.user?.email || null,
    message: authState.error
  }),
  onChange(listener) {
    listeners.add(listener);
    listener({ ...authState });
    return () => listeners.delete(listener);
  },
  async signIn(email, password) {
    throw new Error(authState.error || "Firebase 尚未完成初始化。");
  },
  async signOut() {
    authState.user = null;
    emitChange();
  }
};

window.NursingExamFirebaseAuth = bridge;

if (!missingConfig) {
  bridge.ready = (async () => {
    try {
      const [{ initializeApp }, authModule] = await Promise.all([
        import("https://www.gstatic.com/firebasejs/10.12.5/firebase-app.js"),
        import("https://www.gstatic.com/firebasejs/10.12.5/firebase-auth.js")
      ]);
      const {
        browserLocalPersistence,
        getAuth,
        onAuthStateChanged,
        setPersistence,
        signInWithEmailAndPassword,
        signOut
      } = authModule;
      const app = initializeApp(firebaseConfig);
      const auth = getAuth(app);
      if (firebaseAuthOptions?.sessionPersistence === "browserLocal") {
        await setPersistence(auth, browserLocalPersistence);
      }

      bridge.signIn = async (email, password) => {
        try {
          await signInWithEmailAndPassword(auth, email, password);
        } catch (error) {
          throw new Error(friendlyError(error));
        }
      };

      bridge.signOut = async () => {
        await signOut(auth);
      };

      await new Promise((resolve) => {
        onAuthStateChanged(auth, (user) => {
          authState.user = user;
          authState.ready = true;
          authState.error = "";
          emitChange();
          resolve();
        }, (error) => {
          authState.ready = true;
          authState.error = friendlyError(error);
          emitChange();
          resolve();
        });
      });
    } catch (error) {
      authState.ready = true;
      authState.error = friendlyError(error);
      emitChange();
    }
  })();
} else {
  authState.ready = true;
  emitChange();
}
