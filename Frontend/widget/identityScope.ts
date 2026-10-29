// A new iframe has no in-memory identity. Its persisted scope still belongs to
// the same browser conversation until a different account is identified.
export function identityScopeChanged(
  currentSubject: string | undefined,
  storedSubject: string | null,
  nextSubject: string,
): boolean {
  return (currentSubject ?? storedSubject ?? "") !== nextSubject;
}

export function canUpgradeVisitor(
  currentSubject: string | undefined,
  storedSubject: string | null,
  nextSubject: string,
): boolean {
  return (currentSubject ?? storedSubject ?? "") === "" && nextSubject !== "";
}

// Another tab of the same site wrote the account key this frame shares with
// it. Only a frame that holds an account follows, and only to a different
// one: a frame with no account would wipe the key the other tab just wrote,
// and that tab would reset in turn and close the chat it had just opened.
export function otherTabChangedAccount(
  currentSubject: string | undefined,
  storedSubject: string | null,
): boolean {
  return !!currentSubject && (storedSubject ?? "") !== currentSubject;
}
