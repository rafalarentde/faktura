# PDF fonts

TrueType faces that `App\Pdf\PdfRenderer` registers with Dompdf (see `PdfRenderer::FONTS`).
Every file must cover the Polish and German letters and `€ zł $`; `tests/Pdf/PdfRendererTest.php`
checks the cmap of every `*.ttf` in this directory.

| File | Use |
| --- | --- |
| `SourceSans3-Regular.ttf` | body text (weight 400) |
| `SourceSans3-Bold.ttf` | bold body text (weight 700) |
| `BarlowCondensed-Bold.ttf` | table headers |
| `BarlowCondensed-ExtraBold.ttf` | titles, totals, logo text block |

## Provenance of the static Source Sans 3 files

Dompdf (php-font-lib) cannot pick weights from a variable font: it renders the default instance
(ExtraLight for Source Sans 3) and has no bold. So the two static instances were generated from
the variable `SourceSans3.ttf` (Source Sans 3 version 3.052, Adobe, SIL Open Font License 1.1) with
fontTools 4.68 (`pip install fonttools`):

```python
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

for weight, name in ((400, 'Regular'), (700, 'Bold')):
    font = instancer.instantiateVariableFont(TTFont('SourceSans3.ttf'), {'wght': weight}, updateFontNames=True)
    font.save(f'SourceSans3-{name}.ttf')
```

The variable source file is not kept in the repository. The Barlow Condensed files are the
static releases.

## Replacing a font file

Dompdf caches font metrics and copies of the fonts in `storage/pdf-cache/`. After replacing or
adding a file here, empty `storage/pdf-cache/` (keep `.gitkeep`); the cache is rebuilt on the next
PDF render.

## Licences

Both families are licensed under the SIL Open Font License 1.1. The licence texts with the
copyright lines are `OFL-Barlow.txt` and `OFL-SourceSans3.txt` (copies also sit in
`public/assets/fonts/` next to the web fonts, because the OFL requires the licence to travel
with redistributed font files). The static Source Sans 3 instances above are modified versions
(static instances of the variable font); they are only embedded in generated PDFs and are not
distributed as a font product. Whether the Reserved Font Name "Source" needs attention for that
is a licence nit to confirm if the files are ever offered for download.
