import assert from "node:assert/strict";
import test from "node:test";
import {
  canUpgradeVisitor,
  identityScopeChanged,
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
