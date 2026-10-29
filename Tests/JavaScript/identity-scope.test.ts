import assert from "node:assert/strict";
import test from "node:test";
import {
  canUpgradeVisitor,
  identityScopeChanged,
  otherTabChangedAccount,
} from "../../Frontend/widget/identityScope";

test("reloading the same signed account preserves its stored conversation", () => {
  assert.equal(identityScopeChanged(undefined, "macro:42", "macro:42"), false);
  assert.equal(identityScopeChanged("macro:42", "macro:42", "macro:42"), false);
});
test("visitors keep their conversation when identified again or after reload", () => {
  assert.equal(identityScopeChanged(undefined, null, ""), false);
  assert.equal(identityScopeChanged(undefined, "", ""), false);
});
test("login, logout and a different account change the previous identity scope", () => {
  assert.equal(identityScopeChanged(undefined, null, "macro:42"), true);
  assert.equal(identityScopeChanged(undefined, "macro:42", ""), true);
  assert.equal(identityScopeChanged(undefined, "macro:42", "macro:99"), true);
  assert.equal(identityScopeChanged("macro:42", "macro:99", "macro:99"), true);
});
test("only an anonymous scope can carry its resume credential into login", () => {
  assert.equal(canUpgradeVisitor(undefined, null, "macro:42"), true);
  assert.equal(canUpgradeVisitor("", "", "macro:42"), true);
  assert.equal(canUpgradeVisitor(undefined, "macro:42", "macro:99"), false);
  assert.equal(canUpgradeVisitor("macro:42", "", "macro:99"), false);
  assert.equal(canUpgradeVisitor("macro:42", "macro:42", ""), false);
  assert.equal(canUpgradeVisitor(undefined, null, ""), false);
});
test("a frame without an account ignores another tab writing the shared account key", () => {
  // It would wipe the key the other tab just wrote, and that tab would reset
  // and close the chat it had just opened.
  assert.equal(otherTabChangedAccount(undefined, "macro:42"), false);
  assert.equal(otherTabChangedAccount("", "macro:42"), false);
  assert.equal(otherTabChangedAccount("", null), false);
});
test("a frame holding an account follows another tab signing in with a different one", () => {
  assert.equal(otherTabChangedAccount("macro:42", "macro:99"), true);
  assert.equal(otherTabChangedAccount("macro:42", "macro:42"), false);
});
test("a frame holding an account ignores another tab removing the account key", () => {
  // Logout reaches every tab through its own host page; a tab with an older
  // widget wipes the key on each login, which closed the chat just opened.
  assert.equal(otherTabChangedAccount("macro:42", null), false);
  assert.equal(otherTabChangedAccount("macro:42", ""), false);
});
