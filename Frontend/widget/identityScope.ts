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
// account another tab signed in with. A removal is never followed: logout
// reaches each tab through its own host page, which resets the widget, while
// a tab still running an older widget wipes the key on every login of this
// one, and following that closed the chat the visitor had just opened.
export function otherTabChangedAccount(
  currentSubject: string | undefined,
  storedSubject: string | null,
): boolean {
  return (
    !!currentSubject && !!storedSubject && storedSubject !== currentSubject
  );
}
