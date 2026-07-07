# DaengtisiaMS Design Tokens (UIX-1)

Token semantik dipublikasikan via `tailwind.config.js` (`theme.extend`). **Jangan hardcode `#hex` di Blade** — selalu gunakan class token di bawah.

## Warna brand
| Token | Hex | Penggunaan |
|---|---|---|
| `navy-500` (`text-navy`) | `#0F2540` | Teks utama, brand gelap, heading |
| `navy-700` | `#0A1B32` | Heading tegas, hover navy |
| `brand-500` | `#3B82F6` | Hover CTA |
| `brand-600` (`bg-brand`) | `#2563EB` | **Primary CTA**, active, link, focus |
| `brand-700` | `#1D4ED8` | Active pressed |
| `gold-500` (`text-gold`) | `#C8A45C` | **Aksen premium saja** (garis KPI, highlight) |
| `gold-100` | `#F6EEDD` | Background aksen lembut |

## Surface & teks
| Token | Hex | Penggunaan |
|---|---|---|
| `canvas` (`bg-canvas`) | `#F7F9FC` | Background halaman (off-white) |
| `surface` (`bg-surface`) | `#FFFFFF` | Kartu / tabel / panel |
| `ink` | `#0F2540` | = navy, teks primary |
| `ink-soft` (`text-ink-soft`) | `#5B6B7F` | Teks sekunder |
| `hairline` (`border-hairline`) | `#E3E8EF` | Border subtle |

## Status
| Token | Hex |
|---|---|
| `success` | `#059669` |
| `danger` | `#DC2626` |
| `warning` | `#D97706` |
| `info` | `#2563EB` |

## Radius, shadow, ring
| Token | Nilai |
|---|---|
| `rounded-xl` (kartu) | 12px |
| `rounded-lg` (kontrol) | 8px |
| `shadow-card` | `0 1px 2px rgba(15,37,64,.06), 0 4px 16px rgba(15,37,64,.06)` |
| focus ring | `focus:ring-2 focus:ring-brand-500 focus:ring-offset-2` |

## Density
- **Tabel:** header `px-4 py-3` uppercase meta; sel `px-4 py-3`; baris dipisah `divide-hairline`.
- **Form:** input `px-3 py-2`, gap grup `gap-4`, label `text-sm font-medium text-navy`.
- **Spacing kartu:** `p-6` default (`p-5` padat).

## Badge status → tone
`x-ui.badge :status="..."` memetakan status domain ke tone:
- `draft`→neutral · `waiting`→warning · `in_progress`→info · `cashier_pending`→gold · `paid`/`completed`/`delivered`/`approved`→success · `cancelled`/`rejected`/`out_of_stock`/`expired`→danger · `low_stock`/`expired_soon`/`pending`/`qc`→warning · `normal`→neutral · `info`/`warning`/`danger`/`success` langsung.

## Aturan gold (wajib)
Gold hanya: garis aksen KPI/revenue, highlight kecil premium, badge `cashier_pending`. **Tidak** untuk background besar, **tidak** untuk primary button/CTA, **tidak** untuk teks body.
