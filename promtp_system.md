Act as a Principal Frontend Engineer. I want to completely eliminate native browser dialogs (confirm(), alert(), prompt()) in our application and replace them with a production-ready, custom confirmation modal system.

### 1. Requirements & System Architecture
- **Framework & Libraries**: Built with React / Next.js (App Router compatible), TypeScript, Tailwind CSS, and Lucide React icons. (Optionally use `@radix-ui/react-dialog` or standard HTML `<dialog>` element under the hood for accessible focus management and backdrop logic).
- **Promise-Based Imperative API**: We need a functional trigger like `confirm({ ... })` or `prompt({ ... })` that returns a Promise resolving to `true`/`false` (or the input string/null). This allows us to handle user decisions inline using `await` without restructuring component state everywhere.
  - Example workflow:
    ```ts
    const ok = await confirm({
      title: "Delete item?",
      description: "Are you sure you want to delete lawa_KING? This will also delete all associated logs and metrics.",
      confirmText: "Delete",
      cancelText: "Cancel",
      variant: "destructive"
    });
    if (ok) { // execute deletion logic }
    ```
- **Modal Variants & Options**:
  - `variant`: Support `destructive` (danger/red action button), `warning`, and `default`.
  - `title`, `description` (support string or ReactNode).
  - `confirmText` and `cancelText` overrides.
  - `inputOptions`: Support an optional text input field if mimicking `window.prompt()`.
- **User Experience & Accessibility**:
  - Backdrop blur / overlay animation.
  - Keyboard controls (Escape key to cancel, Enter key to confirm).
  - Focus trapping and automatic focus restoration.
  - Lock body scroll while open.

### 2. Refactoring & Cleanup Instructions
1. **Identify and Remove Native Dialogs**:
   - Search the entire codebase for usage of `window.confirm(`, `confirm(`, `window.alert(`, and `window.prompt(`.
2. **Global Provider Setup**:
   - Wrap the root component/layout with a `<ConfirmDialogProvider />` (or mount a central container component) so dialogs can render top-level in the DOM.
3. **Codebase Migration**:
   - Replace native calls with the new async confirmation function (e.g., `const userConfirmed = await confirmDialog(...)`).

### 3. Deliverables
Please output:
1. The **Promise-based modal state manager/store** or hook (`confirm-dialog-store.ts` or `use-confirm.tsx`).
2. The UI components (**Modal Container & Overlay** using Tailwind CSS, proper focus trapping, and smooth enter/exit animations).
3. The root layout update showing where to mount the modal container.
4. Examples showing:
   - Replaces the attached image's deletion action (`confirm({ variant: 'destructive', ... })`).
   - A standard informational alert confirmation.
   - An interactive prompt with input field.