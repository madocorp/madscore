# madscore

madscore is an SPTK score editor for the Tristaff notation method.

## Requirements

- Linux with a graphical desktop session and PHP 8.2 or newer on the command
  line. Enable FFI, XMLReader, mbstring, and PCNTL. GD is needed for non-PNG
  images handled by SPTK.
- [SPTK](https://github.com/madocorp/SPTK), with SDL3 and SDL3_ttf installed
  as described in its installation instructions. SDL3_ttf also needs FreeType
  and HarfBuzz; PNG images need `libpng16.so.16`.
- Cairo (`libcairo.so.2`) for drawing the score and exporting PDF pages.
- An SDL3 audio playback device to hear the score. The built-in PHP sound
  works without FluidSynth. For SoundFont playback, install the `fluidsynth`
  command and a readable `.sf2` SoundFont.
- `xdg-open` and a browser to open the bundled Tristaff tutorial from Settings.

Check the PHP version and extensions with:

```sh
php --version
php -m | grep -Ei '^(FFI|xmlreader|mbstring|pcntl|gd)$'
```

Install missing PHP extensions and native libraries with your system's package
manager. Package names vary by system.

## Installation

madscore is installed manually; it does not use Composer. This example installs
both projects for one Linux user:

```sh
mkdir -p "$HOME/.local/share" "$HOME/.local/bin"
git clone https://github.com/madocorp/SPTK.git "$HOME/.local/share/SPTK"
git clone https://github.com/madocorp/madscore.git "$HOME/.local/share/madscore"
ln -sfn "$HOME/.local/share/SPTK" "$HOME/.local/share/madscore/SPTK"
ln -s "$HOME/.local/share/madscore/madscore.php" "$HOME/.local/bin/madscore"
```

Install compatible SDL3 and SDL3_ttf shared libraries as explained in the
[SPTK installation guide](https://github.com/madocorp/SPTK#installation).
madscore loads SPTK from a directory or symlink named `SPTK` beside
`madscore.php`. The checkout's relative link works when SPTK is a sibling;
the command above points it at the installed location. Adjust the paths if you
install either project elsewhere. Ensure `~/.local/bin` is in your `PATH`, or
run the PHP script by its full path. The entry point is executable in the
checkout; restore that permission with `chmod +x madscore.php` if needed.

## Running

```sh
madscore
```

You can also run `php madscore.php` from the checkout or installation directory.
Press **F1** to open Settings. Its playback choice switches between SoundFont
and built-in sound. Press **F1** again on Settings to open the offline
[Tristaff tutorial](Tristaff/index.html) in your browser. If SoundFont playback
cannot find a SoundFont in `/usr/share/sounds/sf2/` or
`/usr/share/soundfonts/`, set `MADSCORE_SOUNDFONT` to the path of a `.sf2` file.

## Using madscore

On close, the editor stores the current score, cursor, selection, and visible
bar position in `~/.madscore/session.json`. It restores that
working state at the next start. The Actions block saves and loads named score files.
After saving a score, Ctrl+E exports a PDF and Ctrl+M exports a standard MIDI
file beside the JSON score. MIDI export includes the score's melodies, basic
instrument approximations, drum sounds, tempo changes, and section repeats.
P plays the score and M plays the active melody. An active time selection limits
either mode to that span; full playback follows section repeats. Press the same
shortcut again to stop. Playback uses FluidSynth and an installed `.sf2`
SoundFont when available, falling back to the built in PHP renderer otherwise.
F1 opens Settings from the Actions column. Its Playback button switches between
MIDI SoundFont and the built-in PHP sound. This choice is restored with the session.
Press F1 on the Settings screen to open the illustrated [Tristaff tutorial](Tristaff/index.html)
in the default browser.
Its notation images come from madscore's painters; regenerate them with
`php Tristaff/render-figures.php` after changing the renderer.
The default directory in Settings is saved as you type and is used when a file selector
first opens. If left empty, it uses `~/Music` when available. Later Save and Load
dialogs reopen the last directory browsed.
Linked notes at
the same pitch keep their separate attacks without a gap in MIDI playback.
Set `MADSCORE_SOUNDFONT` to a specific SoundFont path if desired.

Load [`Assets/example.json`](Assets/example.json) for a compact tour of the current features: five melodies,
drums, changing beats and tempo, a repeated section, bar notes, black keys,
fingerings, staccato, slurs, a trill, a glissando, fermatas, and both melody and
score wide dynamic marks. Use Ctrl+M or Ctrl+E to export it.

Application classes follow SPTK's namespace autoloader: `App/` contains the
editor and session UI, `Play/` contains audio synthesis, `Render/` contains
screen and PDF drawing, and `Score/` contains the score model and import logic.

The editor shows four consecutive octave rows
for the selected melody. Their absolute range follows its notes automatically;
the most used octave occupies the second or third row when possible. A melody
can span four octaves from G-sharp to G-sharp, including both endpoint notes;
edits outside that range are rejected. The lower G-sharp sits at the overflow
position below the lowest staff; PDF output places it there without adding a staff.
Other melodies appear in pale versions of their saved colors wherever their notes
fall within those four rows; the selected melody keeps its full color. V opens a paginated PDF preview drawn
by the same page painter as PDF export; Page Up and Page Down turn its pages.
The printed score can show more than four octave bands when several melodies
sound together. The octave with
the most note onsets in the piece is the initial continuous staff. Other
staffs appear only in bars where notes sound. A prescription changes before
the first bar that needs an octave outside its current four bands. Selected
bands stay in pitch order, with a small break between separated ranges. Each selected staff has
an octave symbol on its middle line at the start of a printed line or after a
prescription change: a small black circle marks A440, and one to four stacked
black triangles point up or down for bands above or below it. The selected
melody has its own editable color.
The first four default melody colors are chosen for color printing as well as
screen editing: orange, blue, green, and purple remain distinct from the dark
staff lines on white paper. Later defaults broaden the palette for scores with
more melodies, where the sheet is primarily an editing view. Saved melody colors
and custom hex colors are preserved.
The editor grid is drawn on one canvas above the Bar, Melody, Note, Cursor,
and Actions columns. Each column shows its own controls and shortcuts.
The Actions column opens Composer over the Note tile. Composer uses the unmuted
pitched melodies in the selected time range, or in the full score when there is
no selection. Choose 1–5 versions, sparse/balanced/busy note density, and a
low/middle/high register, then generate. Each version is a separate muted
melody. Select a version with Tab, use Mute in Melody settings to hear it with P
or on its own with M, and delete versions you do not want.
Mute is saved with the score and is separate from Volume. Muted melodies stay
in JSON, but are read-only in the editor until unmuted. Their notes are hidden
on the editor sheet and excluded from playback, PDF, and MIDI export. Unmute a
melody to show, play, or edit it again. Whole-score note edits and beat-count
changes require every melody to be unmuted.
Arrow navigation skips individual headings, help text, and empty filler areas.
The Cursor readout is one selectable tile between Note and Actions.
PDF preview uses the available screen height.
The canvas has one cell of surrounding
background on each side and a five-pixel white inset.

Pitch spacing continues evenly across adjacent octave rows, and notes at a
boundary can span both rows. Vertical bar
separators run only from the first staff line to the third, leaving the space
between octave rows clear.

Section edges extend from the top of G♯ in the highest selected octave to the
bottom of A in the lowest, including the spaces between rows. When a new section
starts at a PDF line break, its edge appears at both the preceding line's end
and the next line's start. The first bar starts
section 1. In Bar settings, use Section start or press | to begin a section at the current
bar or join it to the previous section. The bar readout shows its section number. New bars continue the current
section. The sheet stays blank after the final section edge until a new bar is added.
Set Section repeats in Bar settings to 0–8 extra plays. One horizontal dot above the
last bar of the section means play it once more; two dots mean twice more. The dots
match the octave prescription dot and share the bar-note line. Full score and melody
playback follow repeats; playback of a selected range plays the range once.

The H cursor's vertical sides mark the chosen width,
starting at one beat, and run from A to the G♯ in the octave below. At G♯,
the sides start one pitch position above the note and extend downward. At
higher pitches, they start at the A above. The crossbar runs through
the chosen pitch. It continues across bar and octave boundaries where needed.
The lower endpoint G-sharp has its sides in the overflow octave below the
bottom row.
Faint half-beat guides appear on screen.

Each octave has twelve equally spaced pitch positions. The three staff lines
pass through F♯, D, and A♯ note centers, from top to bottom. A base note's
circle has a diameter of two pitch positions: C and D touch, while E and F overlap. The
horizontal grid has two positions per beat: a base note is centered across two
positions, and a half-base note is a right semicircle centered in one position.
Longer notes connect circular
ends with a straight body. Notes on white piano keys have hollow circles; notes
on black keys are filled, including when an altered spelling reaches that key.
Beat marks inside a long note remain visible where the note crosses a bar edge.
Cairo draws these shapes at screen size and as vector
paths in the PDF.

The sheet editor is active at startup. Press Esc to leave it, and press Esc again
from tile navigation to return to it. The
Functions area shows keyboard help, and each button shows its single-key
shortcut. While editing, Return adds a note at the cursor pitch in a gap or changes
an existing note to the cursor pitch. Space also adds a note in a gap, but on an
existing note it moves the pitch cursor to that note without changing it. Up and
Down move only the pitch cursor by one semitone; the
note under it stays in place until replaced. Left and Right step through notes
and whole gaps between them, landing at note onsets or gap starts. The pitch
cursor stays at its current pitch when navigating to either a note or a gap.
Trailing empty time uses half-beat steps.
A note continuing from the previous bar counts at the bar's first position.
Navigation crosses bar boundaries. The cursor's end adds a bar when it
crosses into it; an unused trailing bar is removed when the cursor's end
leaves it. A note sustained into that bar keeps it.
Home and End jump to the first or last note or gap in the first or last visible
bar. Home lands at the bar start; End lands on its last note or the start of its
trailing gap. Pressing the same key from its edge bar pages backward or forward;
End stops at the score's final bar.
Ctrl+Home/End jumps to the first or last note or gap in the whole score.
Ctrl+A selects the entire score across all melodies.
Shift+Left/Right extends a time selection in the active melody by the same note
and gap steps as ordinary navigation. Shift+Home/End extends it to the visible
page edge. Ctrl+Shift+Left/Right instead selects across every melody one half-beat
at a time; Ctrl+Shift+Home/End reaches the visible edge. Selection stops at the
end of the final bar without adding another. The two scopes have different
highlight colors. With a selection, Up/Down transposes selected notes one semitone
and Page Up/Page Down transposes one octave; otherwise these keys move the pitch
cursor. A whole-score edit splits notes at selection boundaries and preserves
their outside parts. Tab selects
the next existing melody, and Shift+Tab selects the previous one. The pitch cursor
follows that melody's note at the current time, or averages its nearest notes
around a gap; before its first or after its last note, it uses that note's pitch.
An empty melody keeps the current pitch when its octave is visible. Semitone moves cross note names and
octave rows. Each octave runs from A through G-sharp, including the highest
octave; its final G-sharp is drawn above the top line. The spelling of
black keys is chosen consistently; C♯ and D♭ have the same position. - shortens
the cursor or selected note by half a beat; + lengthens it. Keys 1–4 set a
shared level to ppp, pp, p, or mp; 5 clears it; and 6–9 set mf, f, ff, or
fff. Ctrl+1–9 uses the same mapping for a note in the selected melody; a note
must be under the cursor. Key 0 mutes or unmutes the active melody. A–G no
longer have sheet shortcuts. Growing a note uses any gap after it, then pushes
later notes in the same melody right if more room is needed. Shrinking it pulls
later notes left. Empty positions between notes form one selectable gap within
each bar, so Left and Right move across each bar’s gap in one step. Its automatic
cursor width stops at the bar line; length keys can extend the cursor across it.
Changing a gap’s width moves all later notes in the current melody. Gaps between
those later notes keep their sizes. U duplicates the current note at its end,
using its full length. Insert starts at the end of the current note or cursor gap
and inserts a one-beat note there if that beat is empty, or a one-beat gap if it
contains a note. The inserted note uses the cursor pitch. Inserting shifts later
notes in the current melody right and leaves the current note intact.
Other melodies stay in place. The cursor moves to the inserted beat.
Delete removes the selected melody's note or gap under the cursor. With a range
selected, the first Delete clears its notes and the second closes the empty time
by pulling later notes left. Ctrl+C copies the range into app memory. Ctrl+V
replaces a selected range or inserts after the cursor note or gap, shifting later
notes as needed. A melody copy pastes into the active melody; a whole-score copy
keeps its melody numbers. A replacement requires matching selection scopes.
P plays all melodies in the selected time span and M plays the active melody.
With a selection, L links adjacent notes in the active melody and a second L
clears them; * cycles the span through crescendo, decrescendo, and no wedge.
Backspace
removes the preceding note and its following gap, bringing the next note to
the deleted note's onset. Removing a gap pulls
later notes left. A base note fills one beat,
so a four-beat bar has eight cursor positions. A note can continue through
following bars. Notes in the same melody cannot overlap.

Ctrl+Z undoes the latest score edit, and Ctrl+Y redoes it. A group edit across
several melodies is one history step.

A placed note can cross a bar boundary. If it would overlap another note in the
same melody, the editor shows a status message and leaves the score unchanged.
Press S on a note to toggle staccato. A dot in the center of its note head marks
staccato. Press H to toggle a fermata, shown as a triangular end in place of the
note's curved end. A pitched fermata sounds for 1.5 times its written duration,
without shifting later notes. Press L to toggle a curved slur from the selected note to the next note
in the same melody when their written durations meet. Repeated links form a phrase.
The phrase has one melody-colored curve from the first note's left edge to the
last note's right edge, thickest at its middle. It bends downward when the first
note is below D in its octave and upward from D onward.
If an edit separates linked notes, the link is removed. The note panel shows the
selected note's articulation, fermata, and outgoing slur.

Press `\` on a note to toggle a glissando to the next adjacent note in its melody.
Both endpoints must be different white-key pitches on a pitched instrument.
The sheet and PDF draw one hollow sloped note from the first onset to the
second note's end, without beat marks inside it. Playback plays each white key
from the first pitch through the second, dividing the full written beat span
equally between those keys. Turning the glissando off restores the
two ordinary notes; separating their times or changing either endpoint to a
black key clears the link.

Press Ctrl+* on a selected note to cycle its melody wedge through =, +, and -. Plus makes a
crescendo to the next note in the same melody, minus makes a decrescendo, and
equals removes the wedge so the current level holds. The next note may be
across a gap. Consecutive marks of one
direction form a single melody-colored triangle on the bar-note line. Its
bottom edge is horizontal, and its full height matches a repeat dot's diameter.
Press * or the All wedge button to cycle a shared wedge over the selected time
range, or from the current note to the next note in its melody when no range is
selected.
Press / or the Level button to cycle a shared level at the cursor time through
ppp, pp, p, mp, mf, f, ff, fff, and no mark. Keys 1–9 set a shared level
directly; Ctrl+1–9 set it on the selected melody's note. Shared
marks are filled black; melody marks are outlined in their melody colors.
The melody's Volume percentage balances it against other melodies. Playback
fits the score's used levels to a 20–100% range of that volume, with at most
4 dB between adjacent written levels. The fit uses marks from every melody and
shared marks, so adding a quieter or louder mark can change the playback level
of existing marks. Wedges without a closing level count their implicit ppp or
fff endpoint in the fit. A score with only one level keeps its default value.
Shared marks override earlier melody marks, and a later melody mark can take
over until the next shared mark. A written level changes playback at that time
and holds until another level or wedge changes it. A wedge starts at the current
level and reaches the level written on its closing note at that note's onset. Without a
written closing level, a crescendo ends at 100% and a decrescendo at 20%.
A level inside a longer wedge becomes an intermediate point in the volume change.
A level without a wedge is printed at the starting edge of its note's beat.
The PDF places bar notes in an upper notification row and repeat dots, wedges,
and level marks in a separate row below it.

Press W on a note, or use its Trill button, to cycle Off, +1, +2, −1, and −2.
The zigzag inside the note onset begins upward for a higher alternating pitch
and downward for a lower one. One zigzag means one semitone; two stacked
zigzags mean two semitones. Start on the written pitch and alternate for the
note's written length. The mark does not prescribe a speed; playback uses four
attacks per beat. A trill and staccato replace one another because their marks
share the note head. Changing a note beyond the available trill pitch range
clears its trill.

The note tile has a Finger field. Enter L1–L5 or R1–R5 for a left- or right-hand
finger. The larger number sits below the first or last half beat: its left edge
is halfway into the first slot for L, or its right edge is halfway back into the
last slot for R. Its side identifies the hand;
it does not cover the note outline or add a background.
Leave the field blank to remove it.
Fingering is saved with the note and does not change playback. Older saved
numeric finger marks remain unassigned to a hand until edited.

Press P to play every melody together, or M to play only the active
melody. Press the same shortcut again to stop. MIDI playback renders before starting;
stopping uses a 5 ms ramp to avoid a click. When a time selection is visible,
either shortcut plays only that span. The columns below the sheet show
bar settings, melody settings, note settings, cursor pitch and length, and actions. Activate Sound in
the melody column to open one searchable picker over the Note tile. It includes
128 General MIDI instruments and 47 drum sounds. Type the beginning of a sound
name to filter the list; choose a sound to return
to the melody settings. Press R to select Tempo in Bar settings, Y to select
Sound in Melody settings, or T to select Staccato in Note settings; Return
opens the selected control. The Cursor panel is a readout, with its pitch in
the heading. The Color field
below the melody title shows its hex value on that color. Activate it to open
the predefined color grid and choose with the arrow keys. Tab switches to the
hex field, where you can type a six-digit value; Tab switches back to the grid.
Return accepts the color. Set volume from 0 to 100%.
Each melody keeps its own settings. A new score starts with one melody; press N
to add more. Use Delete melody in the melody settings to remove that melody and
its notes after confirming in the status bar. At least one melody must remain.
Sound changes take effect
on the next playback, while color changes update the sheet. A score saves both
the selected MIDI instrument and an approximate PHP fallback sound, so the
instrument choice survives on machines without FluidSynth. Older scores that
saved only a waveform or drum pattern load with a matching MIDI choice. Drum sounds play
one short hit at each note onset. Their pitch and written length do not change
the sound; length still occupies time in that melody, so short notes are useful
for repeated hits. Drum melodies automatically use a half-hourglass `|<` for
half-beat notes and a full hourglass with pointed `>===<` ends from one beat
onward; pitched sounds use round notes. Changing a
melody's sound changes the displayed note shape without changing its notes.
The PHP fallback kick has a boosted low thump and a sharper start so it carries on small
speakers. Snare combines a drum tone with a rattling sound, while clap uses
several close noise bursts. Closed hat ends quickly; open hat has a longer hiss.
Older scores using the former Hat sound select Closed hat. Different melodies
can sound at once. Pitched fallback patterns use harmonics below the audio limit;
Church Organ remains the default MIDI choice. A4 is 440 Hz,
notes follow each bar's BPM, and short envelopes smooth their beginnings and
endings. A note that crosses a bar line follows the new bar's tempo. Audio
requires an available SDL3 playback device.
Ordinary pitched notes stop about 30 ms before their written end, capped at 20%
of their duration. Staccato notes sound for half their written duration. Slurred
notes overlap the next note briefly so the phrase has no audible gap. Drum hits
continue to use their own fixed sound durations.

Title and author are editable above the sheet, with the catalog number to their
right. Every PDF page and preview page prints title and author on the left, with
catalog and the current page count on the right. The score path is chosen in the
Save or Load panel. The preview shows the landscape A4 page as white paper centered on
the app's blue background. The Note block title shows
the note pitch or Gap; its two lines show the cursor pitch and length.
Tempo in BPM, notes per bar, and Bar note belong to the selected bar.
Bar note is printed from that bar's left edge above the top octave in the PDF
and its preview; it does not appear on the editing sheet. The print marks row
shows the first bar's tempo and each later tempo change as a small hollow beat
symbol followed by its BPM, such as ○=120. The tempo symbol and text are drawn
over any wedge at the same position. When a level starts with a tempo mark,
it prints immediately after the BPM, with both marks kept inside the bar.
Changing a bar's tempo also changes each
following bar with the old tempo, stopping at the first different tempo. A new
bar inherits tempo and beat count, and starts with an empty Bar note. Finish an input with Return or
Escape to apply it. Ctrl+S replaces the Note tile with a directory selector,
file name input, and Save/Cancel buttons. Ctrl+W overwrites the current file without a prompt;
Ctrl+O replaces the Note tile with a file selector for loading. Errors and
confirmations appear in the status bar. The current file path survives an editor
restart. Bars are added or removed automatically as the cursor moves. V opens
PDF preview. Its status bar shows the navigation keys: V, Escape, or Return
returns to the editor; Page Up and Page Down turn pages; Home and End jump to
the first and last page. Note editing is disabled in preview. Every bar has a positive tempo,
defaulting to 120 BPM.
Scores use one unversioned JSON format with tempo, a section leader flag, a section repeat count, and optional text in each bar,
pattern, volume, and color for each melody, shared dynamics at score times, and articulation, trill, local dynamics, and fingering on each note. Extra
fields in a music file are ignored. Missing note articulation means an ordinary
note without a slur; a missing trill, dynamic, or finger means no mark. The existing working session is read into this format and
saved without its former version fields.
The Load selector also imports .txt source files downloaded from the web editor.
It shows features that the new editor would omit and asks before accepting the
import. The Actions panel exports a PDF beside the saved JSON score. PDF export produces landscape A4 vector pages using the Sheet prescription,
with line heights adjusted for expanded measures. The editor keeps one sheet surface between frames and repaints it after
cursor or note changes.

## Tests

Run `php Tests/run.php` for score, import, and PDF checks,
`php Tests/Sheet.php` for sheet rendering, `php Tests/Navigation.php` for
cursor movement and gap checks, and `php Tests/Insertion.php` for note insertion.
Run `php Tests/Prescription.php` for adaptive staffs and score migration.
Run `php Tests/Selection.php` for melody selection behavior and its sheet overlay.
Run `php Tests/Sections.php` for section persistence, migration, and drawing.
Run `php Tests/Session.php` for working-state restoration.
Run `php Tests/Files.php` for named JSON saves and file panel layout.
Run `php Tests/Settings.php` for preferences and file browser directory behavior.
Run `php Tests/Audio.php` for synthesized pitch, patterns, volume, timing, and selection checks.
Run `php Tests/Articulation.php` for articulation playback, saving, and drawing.
Run `php Tests/Trill.php` for trill pitch, persistence, playback, and drawing.
Run `php Tests/Glissando.php` for glissando links, drawing, playback, and PDF export.
Run `php Tests/Dynamics.php` for volume wedges, playback, and persistence.
Run `php Tests/Undo.php` for score-wide undo and redo history, and
`php Tests/GroupOperations.php` for selection edits.
Run `php Tests/Fingering.php` for fingering editing, persistence, and drawing.
Run `php Tests/Views.php` for the melody editor range, PDF preview, and dense PDF measures.
Run `php Tests/Palette.php` for default melody color contrast and PDF preview rendering.
Run `php Tests/MelodyCreation.php` for adding melodies and numbered default colors.
Run `php Tests/MelodyDeletion.php` for note cleanup and status bar deletion confirmation.
Run `php Tests/EditorFocus.php` for Escape focus behavior and readout navigation on the editor screen.
Run `php Tests/NotePanel.php` for the dynamic note and cursor headings.
Run `php Tests/BarNotes.php` for per-bar PDF annotations and editing.
Run `php Tests/SheetKeys.php` for separate Space and Return editing behavior.
Run `php Tests/MelodyPitch.php` for Tab's note and gap pitch choices.

## License

madscore is released under the [Unlicense](UNLICENSE).
