{{-- Styles shared by the PM reports. --}}
<style>
    body { font-size: 10pt; }
    section h2 { border-bottom: 1px solid #000; padding-bottom: 1px; }
    .pm-item { margin-top: 12px; page-break-inside: auto; }
    .pm-item-title { font-size: 11pt; font-weight: 600; border-bottom: 1px solid #999; padding-bottom: 2px; page-break-after: avoid; }
    .result { display: inline-block; font-size: 8.5pt; font-weight: 600; border: 1px solid #999; border-radius: 3px; padding: 0 4px; margin-left: 6px; }
    .result-ok { color: #166534; border-color: #166534; }
    .result-issue { color: #b91c1c; border-color: #b91c1c; }
    .result-skipped, .result-pending { color: #555; }
    .photos { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 6px; }
    .photos figure { margin: 0; page-break-inside: avoid; }
    .photos img { width: 100%; height: 45mm; object-fit: cover; border: 1px solid #ccc; }
    .photos figcaption { font-size: 8.5pt; color: #555; }
    .overview td, .overview th { font-size: 9.5pt; }
    /* The footer of the layout is replaced by the PDF's own on every page. */
    .footer { display: none; }
</style>
