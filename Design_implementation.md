# Design System & UI Specification

## 1. Color Theme

| Color | Hex Code | UI Role | Best Practice Usage |
| :--- | :--- | :--- | :--- |
| **Midnight Violet** | `#301934` | Primary Background | Use this for the main canvas background. It gives the UI a rich, premium, nocturnal feel instead of a boring flat black or grey. |
| **Amethyst Smoke** | `#B284BE` | Brand Accent / Highlights | Perfect for interactive elements like buttons, active navigation states, icons, and primary headings. |
| **Lavender Blush** | `#FFF0F5` | Primary Text / Contrasts | Use this very light tint for body text, titles, and high-contrast labels. It reads beautifully against the deep purple background. |

### Essential Implementation Rules

* **i) Watch Out for Text Contrast (Accessibility):** While Lavender Blush (`#FFF0F5`) against Midnight Violet (`#301934`) provides excellent legibility for body text, do not place Amethyst Smoke (`#B284BE`) text directly on the Midnight Violet background for small copy. It lacks the contrast ratio required for comfortable reading. Keep the violet reserved for large headers, icons, or prominent UI borders.
* **ii) Elevate Content with Surface Tints:** If you use the exact same Midnight Violet background for your entire page, your layout will look completely flat. To create depth for cards, modals, or dropdowns, blend your background with a tiny hint of white or opacity to lift it closer to the user.

---

## 2. Dashboard Typography Rules

* **Font Selection:** Use the **Lato** font from Google Fonts.
* **Use a Single Font Family:** Do not mix multiple different fonts. Instead, create contrast by varying font weights (e.g., Bold for titles, Regular for body text).
* **Stick to 3 Sizes Max:** Use different sizes for headers, data points, and labels to build a clear hierarchy.
* **Avoid Serif Fonts:** Decorative or Serif fonts (like Times New Roman) are difficult to read in dense tables and charts.

---

## 3. The "Negative Constraints" Rule

### Design Constraints & Restraint Rules:
* **NO** decorative box-shadows or neon glow effects unless explicitly requested for a specific element.
* **NO** hover scaling, multi-axis translations, or complex layout shifting animations. Hover states must strictly be clean, predictable color transitions (e.g., subtle opacity or minor background shade shifts).
* **DO NOT** invent accent colors. Stick to the defined palette. If a neutral background or surface color is needed, use a clean desaturated scale, not a saturated tint.

---

## 4. Design Philosophy

> **Role Execution:** Act as an Award-Winning Creative Frontend Developer and Interactive Designer.

*"The aesthetic must be highly functional, clean, and restrained. Prioritize typographic hierarchy, generous white space, and sharp structural alignment over decorative visuals. Every style choice must serve a structural or interactive purpose."*

## 5. Geometric & Border Radius Rules (Tailwind 2xl Precision)

* **Primary Component Radius (Tailwind 2xl Equivalent):** All standard containers, layout sections, table cards, main dashboards panels, and modals must strictly use a soft, modern geometric radius of **`border-radius: 16px;`** (or `1rem`). 
* **Nested Element Radius (Concentric Alignment):** Buttons, inputs, search fields, and dropdown controls sitting *inside* the primary cards must use a smaller, proportional radius of **`border-radius: 8px;`** (or `0.5rem`) to `12px` (`0.75rem`). This preserves a perfect, visually clean concentric geometric alignment where inner corners match outer shapes.
* **Pill Restrictions:** Do **NOT** use fully rounded pills (`border-radius: 9999px;`) unless rendering standalone notifications, user avatars, or status badges.
* **Structural Boundaries:** Separate your dashboard views using flat, clean `1px solid` borders using your desaturated surface color scale. Avoid any layout dividers or component separation techniques that rely on heavy blurred box-shadow properties.

---

## 6. Icon Library & Integration (Remix Icon)

To render clean iconography without JavaScript compilation or build frameworks, inject the global Remix Icon font CDN into the template header.

* **CDN Link (Add to HTML `<head>` or PHP layout header):**
  ```html
  <link href="[https://cdn.jsdelivr.net/npm/remixicon@4.7.0/fonts/remixicon.css](https://cdn.jsdelivr.net/npm/remixicon@4.7.0/fonts/remixicon.css)" rel="stylesheet" />