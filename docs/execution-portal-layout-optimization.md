# WaterBonia layout optimization

The public interface uses **Water Dispensing System** beneath the WATERBONIA sidebar brand. Each sidebar link opens its own content view, retaining the compact styling and four-step dispensing workflow.

## Navigation and branding

The 220px desktop sidebar provides Dispense Water, Container Settings, Device Status, Activity Logs, Safety Sequence, and Demo Controls when enabled. A collapsible menu provides the same links below 1000px. Active states, focus, URL fragments, browser history, and active dispensing state are preserved.

The toolbar displays the selected view title and compact system/demo indicators. The header does not repeat the brand or long project description. The footer contains a short copyright line. Only the selected view is visible, and each view scrolls naturally as needed.

## Layout changes

| Element | Previous layout | Current layout |
| --- | --- | --- |
| Header | Repeated branding; 72px minimum | View title/status; 56px minimum |
| Main content | 28px top spacing, repeated overrides | One scoped portal layout; 20px desktop top spacing |
| Primary card | Up to 43px padding and 492px minimum height | 24px padding, natural height |
| Container cards | 238px minimum height; inherited 140px width | 176px minimum height, equal full-width tracks |
| Artwork | 84px circles | Existing Cup/Tumbler illustrations in 60px circles |
| Step rail | 25px lower margin | Horizontal circles/connectors; 12px lower margin |
| Confirmation tiles | 119px minimum height | 80px minimum height |
| Threshold | Reservoir-minimum selector | Numeric dispensing amount in Place and Container Settings |
| Actions | Primary and Change container stacked | Adjacent desktop actions; stacked on mobile |
| Summary values | Ellipsis and no wrapping | Full text wraps naturally |

Public styles are consolidated in one scoped stylesheet section. Shared maintenance styles remain. Page zoom, interface scaling, fixed content-height caps, and overflow clipping are not used to force content to fit.

Device Status balances connection, presence, pump, and RGB information. Demo Controls uses a compact scenario-button grid. Container Settings provides two independent numeric inputs with validation feedback. Narrow screens stack container cards and controls.

## Current threshold model

The water-level sensor has been removed. Water Availability is replaced with Container Settings, and reservoir indicators, calibration requirements, low-water banners, and reservoir demo controls are removed.

Each user-selected threshold is the actual amount dispensed, measured by the existing HX711 load cell. Cup defaults to 250 mL and Tumbler to 500 mL; whole-number amounts from 1–2000 mL are initially permitted. A 150 mL Cup selection fills to 150 mL. The server validates the value, and the accepted target stays fixed for a pending or active operation.

The firmware defers blocking network requests while the pump runs to keep local cutoff checks active. Hardware progress readings pause until the pump stops and the final measured result is reported. Demo progress remains continuous.

Activity Logs retain date and time, meaningful events, details, status, View More, and isolated demo-history controls. See [Dispensing implementation](execution-portal-revision.md) for backend, firmware, configuration, and history behavior.

## Verification

- 51 guest HTTP checks passed against XAMPP Demo Mode at `http://localhost/WaterBonia/`.
- 40 controller checks passed, including custom amounts, bounds, automatic 100% completion/pump OFF, detection/start separation, interruption, target preservation, and timestamped history.
- 18 navigation checks passed, including dedicated views, title changes, deep links, history, mobile menu behavior, focus, and preserved dispensing state.
- 103 backend safety/API handler checks and 14 public activity projections passed with isolated fixtures. PHP/JavaScript syntax and whitespace checks pass.

The suites verify rendered structure and controller behavior. They do not establish visual viewport fit or physical dispensing accuracy.

## Viewport limits

| Requested viewport | Final visual check |
| --- | --- |
| 1366 × 768 | Unavailable: no browser automation surface connected |
| 1440 × 900 | Unavailable |
| 1600 × 900 | Unavailable |
| 1920 × 1080 | Unavailable |
| Tablet/mobile | Responsive CSS implemented; final visual check unavailable |

The supplied screenshot was reviewed for branding and layout context. It predates the final edits. Normal-zoom checks for clipping, horizontal overflow, and primary-action visibility remain outstanding. Hardware measurement requires calibrated HX711 hardware; the latest firmware was not built or flashed here.
