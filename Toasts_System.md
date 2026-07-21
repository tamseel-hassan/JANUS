Act as a Principal Frontend Engineer. I want to completely remove our legacy/deprecated toast notification system and implement a robust, modern, accessible, and performant toast system. Use under the hood for accessibility and motion, styled with Tailwind


### 1. Requirements & System Architecture
- **Framework & Libraries**: Build using React / Next.js (App Router compatible), TypeScript, Tailwind CSS, and Lucide React icons.
- **State Management**: Use a lightweight, centralized state store (or custom event emitter / custom React hook pattern like `useToast()` / `toast()`) so toasts can be triggered from anywhere—both inside React components and outside (e.g., inside API response interceptors, helper utils, or async actions).
- **Toast Types**: Support `success`, `error`, `warning`, `info`, and `loading` (with support for promise resolution/updating state).
- **Customization & Variants**: Support titles, description text, custom action buttons, auto-dismiss duration overrides, and manual dismiss (`onDismiss`).
- **Accessibility (a11y)**: Follow WAI-ARIA guidelines for Live Regions (`aria-live="polite"` or `assertive`, correct `role="status"` or `role="alert"`).
- **Animations & Layout**: 
  - Smooth enter/exit transitions (fade and slide).
  - Support for position anchors (e.g., `top-right`, `bottom-right`, `top-center`).
  - Stacking behavior or queue limit to prevent DOM cluttering.

### 2. Refactoring & Cleanup Instructions
1. **Identify and Remove Old Implementation**:
   - Locate and delete old toast context providers, toast UI components, and custom hooks tied to the old system.
   - Remove any legacy dependencies or CSS overrides associated only with the old toast system.
2. **Global Integration**:
   - Wrap the application root (`layout.tsx` or `App.tsx`) with the new `<ToastProvider />` / `<Toaster />` viewport container.
3. **Migration & Deprecation Replacement**:
   - Scan the codebase for all occurrences of the old toast trigger function calls (e.g., `oldToast()`, `useOldToast()`, etc.).
   - Refactor them to use the new `toast()` helper functions (e.g., `toast.success("Message")`, `toast.error("Message")`, `toast.promise(...)`).

### 3. Deliverables
Please output:
1. The centralized **toast store / event logic** file (`toast-store.ts` or `use-toast.ts`).
2. The UI components (**Toaster container** and **individual Toast view** using Tailwind CSS).
3. The root layout component update showing where to place the `<Toaster />`.
4. Examples showing:
   - Basic usage inside a component.
   - Usage outside a React component (e.g., inside an async fetch error handler).
   - A promise-based toast (`toast.promise`).