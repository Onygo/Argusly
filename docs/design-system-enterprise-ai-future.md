# Enterprise AI Future Design System

## Scope
- Public frontend
- Client app (`/app`)
- Admin (`/admin`)

This design system is visual-only and does not change business logic.

## Core Tokens
- `background`: `#F4F6F8`
- `surface`: `#FFFFFF`
- `surfaceMuted`: `#F0F2F5`
- `border`: `#E2E5EA`
- `textPrimary`: `#181A1F`
- `textSecondary`: `#6B7280`
- `textMuted`: `#8A94A6`
- `textInverse`: `#F5F7FA`
- `navBg`: `#111318`
- `navSurface`: `#1A1D24`
- `navBorder`: `#2A2E37`
- `navText`: `#C7CBD3`
- `navTextMuted`: `#9AA3B2`
- `aiPrimary`: `#7CF29C`
- `aiHover`: `#65E487`
- `aiPressed`: `#52D977`
- `aiSoftBg`: `rgba(124, 242, 156, 0.12)`
- `aiSoftRing`: `rgba(124, 242, 156, 0.22)`
- `success`: `#22C55E`
- `warning`: `#F59E0B`
- `danger` (`destructive`): `#EF4444`
- `info`: `#3B82F6`

## Elevation + Radius
- `shadow-sm`: `0 1px 2px rgba(16, 24, 40, 0.06)`
- `shadow-md`: `0 6px 14px rgba(16, 24, 40, 0.08)`
- `shadow-lg`: `0 14px 30px rgba(16, 24, 40, 0.10)`
- `rounded-sm`: `10px`
- `rounded-md`: `14px`
- `rounded-lg`: `18px`
- `rounded-xl`: `22px`

## Typography
- Primary font family: `Inter`
- Headings: `font-semibold`
- Body: `font-normal`
- Labels/meta: `font-medium`

## Component Usage
- Primary action: `bg-aiPrimary text-textPrimary hover:bg-aiHover`
- Secondary action: `bg-surface border border-border text-foreground hover:bg-surfaceMuted`
- Inputs: `bg-surface border-input focus:ring-ring focus:border-aiPrimary`
- Sidebar/nav layer: `bg-navBg border-navBorder text-navText`
- Active nav item: `bg-aiSoftBg border border-aiSoftRing text-aiPrimary`
- Tables: header `bg-surfaceMuted`, row hover `rgba(24, 26, 31, 0.03)`

## Layout Conventions
- Desktop page gutters: `md:px-6`
- Mobile gutters: `px-4`
- Main cards: `p-6`
