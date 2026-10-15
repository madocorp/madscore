# madscore

madscore is an SPTK score editor for the Tristaff notation method.

On close, the editor stores the current score, cursor, selection, and visible
bar position in `~/.madscore/session.json`. It restores that
working state at the next start. The Files screen remains available for named
score files.

Run `php madscore.php` from this directory. The app needs PHP FFI, Cairo,
SDL3, and SDL_ttf through SPTK. Its score editor supports nine octave bands,
from A0 through G♯8, and shows up to four in a prescription. The octave with
the most note onsets in the piece is the initial continuous staff. Other
staffs appear only in bars where notes sound. A prescription changes before
the first bar that needs an octave outside its current four bands. Selected
bands stay in pitch order, with a small break between separated ranges. A bar
cannot contain notes in more than four octave bands. Each selected staff has
an octave symbol on its middle line at the start of a printed line or after a
prescription change: a small black circle marks A440, and one to four stacked
black triangles point up or down for bands above or below it. The selected
melody has its own editable color.
The sheet is drawn on one canvas occupying about half the window height,
with room below for the rest of the editor. It has one cell of surrounding
background on each side and a five-pixel white inset.

Pitch spacing continues evenly across adjacent octave rows, and notes at a
boundary can span both rows. Vertical bar
separators run only from the first staff line to the third, leaving the space
between octave rows clear.

Section edges extend from the top of G♯ in the highest selected octave to the
bottom of A in the lowest, including the spaces between rows. The first bar starts
section 1. Press F8 on another bar
to start a section there, or press it again to join that bar to the previous
section. The bar readout shows its section number. New bars continue the current
section. The sheet stays blank after the final section edge until a new bar is added.

The H cursor's vertical sides mark the chosen width,
starting at one beat, and run from A to the G♯ in the octave below. At G♯,
the sides start one pitch position above the note and extend downward. At
higher pitches, they start at the A above. The crossbar runs through
the chosen pitch. It continues across bar and octave boundaries where needed.
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

Press Return to activate the sheet editor, and Esc to leave it. While editing,
Return places or replaces a note. Left and Right step through notes and whole
gaps between them, landing at note onsets or gap starts. Trailing empty time
uses half-beat steps.
A note continuing from the previous bar counts at the bar's first position.
Navigation crosses bar boundaries. Ctrl+Left and Ctrl+Right jump to the first
position of the previous or next bar. The cursor's end adds a bar when it
crosses into it; an unused trailing bar is removed when the cursor's end
leaves it. A note sustained into that bar keeps it.
Home and End jump to the first note in the active melody in the first or last
visible bar, or to that bar's start if it has no note in the melody. Pressing
the same key from its edge bar pages backward or forward; End stops at the
score's final bar.
Shift+Left and Shift+Right extend a time
selection in the active melody by the same note and gap steps as ordinary
navigation. Selection stops at the end of the final bar without adding another.
The selection is highlighted across all four octave rows. Up and Down move the pitch by one
semitone. Page Up and Page Down choose an octave. Tab and Shift+Tab cycle
melodies. A–G set a natural pitch and place a note at an empty cursor position.
On an existing note, they change its pitch. Semitone moves cross note names and
octave rows. Each octave runs from A through G-sharp, including the highest
octave; its final G-sharp is drawn above the top line. The spelling of
black keys is chosen consistently; C♯ and D♭ have the same position. Backspace
shortens the cursor width by half a beat; Space lengthens it. Key 0 sets a
half-beat width, and keys 1–9 set whole-beat widths. When the cursor is on a
note, pitch and width keys edit that note immediately. Growing a note uses any
gap after it, then pushes
later notes in the same melody right if more room is needed. Shrinking it pulls
later notes left. Empty positions between notes form one selectable gap within
each bar, so Left and Right move across each bar’s gap in one step. Its automatic
cursor width stops at the bar line; length keys can extend the cursor across it.
Changing a gap’s width moves all later notes in the current melody. Gaps between
those later notes keep their sizes. Insert adds a
one-beat base note after the current note with the same pitch and alteration;
Shift+Insert duplicates its full length. Both commands push later notes in the
current melody right by the inserted note's full length, preserving their gaps.
Other melodies stay in place. The cursor moves to the inserted note.
Delete removes the selected melody's note under the cursor. On a gap,
Delete removes that gap from the melody's timeline and pulls
later notes left. A base note fills one beat,
so a four-beat bar has eight cursor positions. A note can continue through
following bars. Notes in the same melody cannot overlap.

A placed note can cross a bar boundary. If it would overlap another note in the
same melody, the editor shows a status message and leaves the score unchanged.

Press P to play every melody together, or Shift+P to play only the active
melody. Press the same shortcut again to stop. Playback starts immediately;
stopping uses a 5 ms ramp to avoid a click. When a time selection is visible,
either shortcut plays only that span. The three columns below the sheet show
note details, bar settings, and the active melody's sound settings. Choose Sine,
Organ, Triangle, Square, Sawtooth, Kick, Snare, Clap, Closed hat, or Open hat in
the melody column. Set volume from 0 to 100% and color as a six-digit hex value.
Each of the twelve melodies keeps its own settings; sound changes take effect
on the next playback, while color changes update the sheet. Drum sounds play
one short hit at each note onset. Their pitch and written length do not change
the sound; length still occupies time in that melody, so short notes are useful
for repeated hits. Kick has a boosted low thump and a sharper start so it carries on small
speakers. Snare combines a drum tone with a rattling sound, while clap uses
several close noise bursts. Closed hat ends quickly; open hat has a longer hiss.
Older scores using the former Hat sound select Closed hat. Different melodies
can sound at once. Pitched patterns use harmonics below the audio limit;
Organ remains the default. A4 is 440 Hz,
notes follow each bar's BPM, and short envelopes smooth their beginnings and
endings. A note that crosses a bar line follows the new bar's tempo. Audio
requires an available SDL3 playback device.

Title and author are editable above the sheet, with catalog number and filename
to their right. A three-line readout below the sheet shows the current bar,
the selected note's pitch or Gap when no note is selected, and the cursor
length. Tempo in BPM and notes per bar beside it belong to the selected bar;
a new bar inherits both settings. Finish an input with Return or
Escape to apply it. The filename uses the directory selected on the Files
screen. F3 opens file actions. F5 appends a bar, F6 saves, and F7 opens PDF
export. Every bar has a positive tempo, defaulting to 120 BPM.
Scores use version 6 JSON with tempo and a section leader flag stored in each bar,
plus pattern, volume, and color settings for each melody. Earlier scores and the existing
working session load with Organ at 100% for every melody, retaining the first six
melodies and adding six empty ones. Older scores
are loaded as one section. Older score-wide
tempos are copied into each bar; missing or nonnumeric ones use the default. Version 1
octaves are also converted to absolute octave numbers.
The Files screen can import source files downloaded from the web editor. It
shows features that the new editor would omit and asks before accepting the
import. PDF export produces landscape A4 vector pages and fits bars to printed
lines. The editor keeps one sheet surface between frames and repaints it after
cursor or note changes.

Run `php Tests/run.php` for score, import, and PDF checks,
`php Tests/Sheet.php` for sheet rendering, `php Tests/Navigation.php` for
cursor movement and gap checks, and `php Tests/Insertion.php` for note insertion.
Run `php Tests/Prescription.php` for adaptive staffs and score migration.
Run `php Tests/Selection.php` for melody selection behavior and its sheet overlay.
Run `php Tests/Sections.php` for section persistence, migration, and drawing.
Run `php Tests/Session.php` for working-state restoration.
Run `php Tests/Audio.php` for synthesized pitch, patterns, volume, timing, and selection checks.
