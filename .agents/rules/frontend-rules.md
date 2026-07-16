---
trigger: glob
globs: nextjs-frontend/**/*
---

# Frontend Architecture & Style Guide (Next.js & GSAP Stack)

## Core Stack
- **Framework:** Next.js (App Router, React 19)
- **Language:** TypeScript (Strict Mode, NO `any`)
- **Styling:** Tailwind CSS + `cn` utility (`clsx` + `tailwind-merge`)
- **Animation:** GSAP (`@gsap/react` package)
- **Scrolling:** Lenis (`useLenis` hook)

---

## 1. Component Architecture & State
- **Server First:** Default to Server Components. Apply `'use client'` strictly when hooks (`useState`, `useLenis`) or GSAP animations are required.
- **Size Limit:** Keep components single-responsibility. Split any file exceeding ~150 lines or managing multiple concerns.
- **Directory Structure:** All files must strictly map to these boundaries:
  - `/components` (Feature components)
  - `/components/ui` (Atomic primitives; extract repeated Tailwind strings here)
  - `/hooks` (Custom hooks for stateful logic shared across 2+ places)
  - `/lib` & `/lib/schemas` (Zod schemas, configurations)
  - `/types` & `/utils` (Pure types and pure utility functions)
- **State Ladder:** Local `useState` ➔ React Context (Low-frequency global state like auth/theme) ➔ Zustand/Jotai (High-frequency shared client state). Max prop-drilling depth: 2 levels.

---

## 2. TypeScript & Validation
- **Typing Props:** Declare component props exclusively using `interface Props`. No inline type literals.
- **Environment Variables:** Validate all env vars at startup via a Zod schema in `/lib/env.ts` called in `next.config.ts`. Force a build-time crash on missing variables.
- **Data Parsing:** Pass all API route inputs, form submissions, and third-party API payloads through Zod schemas before use.

---

## 3. GSAP & Animation Performance
- **Lifecycle Management:** Use the `useGSAP()` hook from `@gsap/react`. Fall back to `gsap.context()` only if `useGSAP` is missing.
- **Performance Rules:** Never animate layout shifting properties (`top`, `left`, `width`, `height`, `margin`, `padding`). Animate transform properties exclusively: `x`, `y`, `scale`, `rotation`, and `opacity`.
- **Accessibility:** Wrap complex timelines in a `matchMedia('(prefers-reduced-motion: reduce)')` check to gracefully degrade animations for users with motion sensitivities.

---

## 4. Performance & Core Next.js Optimization
- **Images:** Force the use of `next/image`. Never use raw `<img>` tags.
- **Data Fetching:** Explicitly declare `cache` and `revalidate` on every `fetch()`. Never drop `fetch` inside loops; use React `cache` or `unstable_cache` for deduplication.
- **Code Splitting:** Use dynamic imports (`next/dynamic`) for heavy third-party bundles not needed on initial viewport render.
- **Metadata:** Export typesafe metadata objects via the `Metadata` type from layouts or pages. Do not inject `<head>` tags manually.

---

## 5. Security & Error Boundaries
- **HTML Injection:** Do not use `dangerouslySetInnerHTML` without a sanitizer like `DOMPurify`.
- **Secret Separation:** Ensure server-only secrets omit the `NEXT_PUBLIC_` prefix.
- **Headers:** Define `X-Frame-Options`, `Content-Security-Policy`, `Strict-Transport-Security`, and `X-Content-Type-Options` directly inside `next.config.ts`.
- **Boundaries:** Co-locate `error.tsx` in any route segment processing async actions. Wrap heavy server component sub-trees in `<Suspense>` paired with a localized `loading.tsx`. Never swallow catch blocks without forwarding to an observability log.

---

## 6. Code Quality & Testing
- **Clean Commits:** Strip all dead code, unused imports, and temporary logging variables before staging.
- **Documentation:** Code must be highly self-documenting. Reserve comments strictly for non-obvious business logic or intricate GSAP timelines.
- **Test Strategy:** Write unit tests (`*.test.ts` via Vitest) for custom hooks and utilities. Write integration tests via React Testing Library for multi-step interactive workflows.