# Design System & UI Specification


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