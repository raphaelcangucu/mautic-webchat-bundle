// A new iframe has no in-memory identity. Its persisted scope still belongs to
// the same browser conversation until a different account is identified.
export function identityScopeChanged(
  currentSubject: string | undefined,
  storedSubject: string | null,
  nextSubject: string,
): boolean {
  return (currentSubject ?? storedSubject ?? "") !== nextSubject;
}
