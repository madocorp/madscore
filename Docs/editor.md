# madscore editor reference

The [README](../README.md) covers installation and startup. This page collects
details that are useful when editing a score or checking how madscore treats it.
The interface also shows shortcuts beside its controls.

## The editor

The sheet is active at startup. Press **Esc** to move to the controls below it;
press **Esc** again to return to the sheet. The controls are grouped into Bar,
Melody, Note, Cursor, and Actions. **Tab** and **Shift+Tab** switch melodies.
The Cursor block shows the current pitch and length.

The selected melody uses its full color. Other melodies are shown in paler
colors where their notes fall within the four visible octave rows. These rows
follow the selected melody's notes. A melody may span four octaves from G♯ to
G♯, including both endpoints.

## Notes and navigation

Each octave has twelve equally spaced pitch positions. The three staff lines
cross A♯, D, and F♯. White piano keys use hollow note heads; black keys use
filled ones. Time has two positions per beat. A round note lasts one beat, a
half-circle lasts half a beat, and longer notes stretch across more positions.
Marks inside long notes identify further beats. A note may cross a bar line.

- **Left/Right** moves between note onsets and whole gaps. **Up/Down** moves
  the pitch cursor by one semitone without changing a note.
- **Return** adds a note in a gap or changes an existing note to the cursor
  pitch. **Space** also adds a note in a gap; on a note, it moves the cursor to
  that note's pitch.
- **- / +** shortens or lengthens the cursor or current note by half a beat.
  Growing a note uses an available gap, then moves later notes in that melody
  if more room is needed. Notes in one melody cannot overlap.
- **Home/End** goes to the first or last position in the visible bars. Pressing
  again at an edge pages through the score. **Ctrl+Home/End** goes to the ends
  of the entire score.
- **U** duplicates a note at its end. **Insert** inserts a note or gap and
  moves later notes in that melody. **Delete** removes the current note or gap;
  **Backspace** removes the preceding note and closes the space.
- **Ctrl+Z / Ctrl+Y** undoes or redoes a score edit. A group edit across
  melodies is one history step.

The cursor adds a new bar when it moves past the score's end. An unused last
bar is removed when the cursor moves back. A sustained note keeps its bar.

## Selecting time

**Shift+Left/Right** extends a selection in the active melody.
**Ctrl+Shift+Left/Right** selects across all melodies in half-beat steps.
**Shift+Home/End** and **Ctrl+Shift+Home/End** extend those selections to the
visible edge. **Ctrl+A** selects the entire score.

With a selection, **Up/Down** transposes by a semitone and **Page Up/Page Down**
transposes by an octave. **Ctrl+C** copies; **Ctrl+V** replaces a selected span
or inserts after the cursor. A melody selection pastes into the active melody;
a whole-score selection keeps the melody numbers. The first **Delete** clears
selected notes, and a second **Delete** closes the empty time.

## Melodies and sound

Press **N** to add a melody. Melody settings choose its instrument, color,
volume, and mute state. The sound picker includes General MIDI instruments and
drum sounds. A score stores the MIDI choice and an approximate built-in sound.

Muted melodies remain in the JSON file, but are hidden from the sheet and
excluded from playback, PDF, and MIDI export. Unmute one to edit it. Whole-score
edits and beat-count changes require every melody to be unmuted.

Press **P** to play the score or **M** to play the active melody; press the same
key again to stop. A time selection limits playback to that span. Full playback
follows section repeats. Settings switches between SoundFont playback through
FluidSynth and the built-in PHP sound. Drum hits have fixed sound durations;
their written lengths still occupy time in the melody. Drum notes use a pointed
hourglass shape instead of a round note head.

## Expression marks

- **S** toggles staccato, which shortens the sound. **H** toggles a fermata,
  which extends it without moving later notes. **L** joins an adjacent next
  note with a slur; the link is removed if an edit separates the notes.
- **W** cycles a trill through off, one or two semitones up, and one or two
  semitones down. The mark says which pitch to alternate with; it does not
  prescribe the speed.
- `\` joins adjacent notes on different white keys with a glissando. The
  two notes must belong to a pitched melody. Playback moves through the white
  keys between them.
- The Finger field accepts **L1–L5** or **R1–R5**. The printed number sits at
  the lower left or right of the note. It does not change playback.

Written volume levels run from **ppp** to **fff**. **1–9** sets or clears a
level shared by all melodies; **Ctrl+1–9** sets one on the selected melody's
note. **/** cycles a shared level at the cursor. `*` cycles a shared
crescendo or decrescendo over the selection; `Ctrl+*` sets one from a note
to the next in its melody. Shared marks are filled black. Melody marks are
outlined in that melody's color. A written level remains in effect until a
later mark changes it.

## Bars, sections, and print

Bar settings hold its beat count, tempo, and optional note. A new bar inherits
beat count and tempo. Changing a tempo also updates following bars that still
have the previous tempo. Bar notes appear above the printed score.

Every score starts with a section. Use **|** or the Bar control to start a new
section at the current bar. A section can repeat up to eight extra times. Dots
above its last bar show the repeat count. A selection plays once, even if it
contains a repeated section.

**V** opens the PDF preview. **Page Up/Page Down** turns pages; **Home/End**
jumps to the first or last page. **V**, **Esc**, or **Return** closes it. The PDF
uses landscape A4 vector pages. It may show more octave bands than the editor's
four rows so that melodies in different ranges can appear together. A dot marks
the A4–G♯5 band, and triangles mark higher or lower bands.

## Files and settings

**Ctrl+S** opens Save, **Ctrl+W** overwrites the current JSON file, and
**Ctrl+O** opens Load. Load can also import `.txt` files from the web editor;
it warns about unsupported features before importing. **New** in the Load panel
starts an empty, unnamed score. Use **Ctrl+S** to name it before **Ctrl+W** can
overwrite a file. After saving, **Ctrl+E**
exports a PDF and **Ctrl+M** exports a MIDI file beside the JSON file.

The current score, cursor, selection, and visible bar position are restored
from `~/.madscore/session.json` when the app starts again. Settings stores its
default directory as it is edited. The first file selector starts there, or
in `~/Music` when no directory is set; later selectors remember the last
directory browsed. Press **F1** from the editor to open Settings and **F1**
there to open the offline [Tristaff tutorial](../Tristaff/index.html).
