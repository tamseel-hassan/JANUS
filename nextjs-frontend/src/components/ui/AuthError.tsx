export function AuthError() {
  return (
    <div className="bg-bg-card border-l-4 border-red-500 p-6 rounded-2xl">
      <h2 className="text-xl font-semibold text-red-400">Authentication Required</h2>
      <p className="text-text-muted mt-2">
        Please ensure you are logged into the original application.
      </p>
    </div>
  );
}

export default AuthError;
