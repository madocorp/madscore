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

On the score sheet, **Esc** opens tile navigation. The Bar, Melody, Note, and
Actions boxes each act as one tile: use arrows to select a box and **Return** to
enter its controls. **Esc** from a box returns to score editing. While navigating
inside a box, **R**, **Y**, and **T** jump to Bar, Melody, and Note controls.
When editing a text field, the first **Esc** finishes the field and the next
returns to the score.

Each octave has twelve equally spaced pitch positions. The three staff lines
cross A♯, D, and F♯. White piano keys use hollow note heads; black keys use
filled ones. Time has two positions per beat. A round note lasts one beat, a
half-circle lasts half a beat, and longer notes stretch across more positions.
Marks inside long notes identify further beats. A note may cross a bar line.

- **Left/Right** moves between note onsets and whole gaps. **Up/Down** moves
  the pitch cursor by one semitone without changing a note.
- **Return** adds a note in a gap or changes an existing note to the cursor
  pitch. On a chord, **Return** adds the cursor pitch without changing its other
  tones. **Space** also adds a note in a gap; on a chord, it moves the pitch
  cursor to the first-added surviving tone (the base note). **Shift+Space** and
  **Shift+Return** add a chord tone without advancing.
  Existing pitches are not duplicated. Every tone in a chord starts together
  and has the same length; use separate melodies for independent rhythms.
- **- / +** shortens or lengthens the cursor or current note by half a beat.
  Growing a note uses an available gap, then moves later notes in that melody
  if more room is needed. A chord's tones resize together. **Page Up/Page Down**
  moves the pitch cursor on a single note, or transposes every tone of a chord
  by an octave.
- **Home/End** goes to the first or last position in the visible bars. Pressing
  again at an edge pages through the score. **Ctrl+Home/End** goes to the ends
  of the entire score. Moving past a visible edge scrolls by half a page so
  several more bars are ready without another redraw.
- **U** duplicates a note at its end. **Insert** inserts a note or gap at the
  cursor's left edge and moves later notes in that melody. It inserts a gap
  only between adjacent notes; otherwise the new note uses the cursor pitch.
  **Delete** removes the current chord or gap; **Shift+Delete** removes only the
  chord tone at the pitch cursor. **Backspace** removes the preceding chord and
  closes the space.
- **Ctrl+Z / Ctrl+Y** undoes or redoes a score edit. A group edit across
  melodies is one history step.

The cursor adds a new bar when it moves past the score's end. An unused last
bar is removed when the cursor moves back. A sustained note keeps its bar.

## Motif generator

Press **C** on the editor sheet to open the Composer tile in place of the Note
tile. In the main score, **M** opens the motif editor. The first visit creates
an empty motif; later visits return to the last one used. Each motif has its
own editable score and an uppercase ID such as A, B, or AA. The top row shows
the motif ID, an editable name, and its scale and home note. Activate the motif
row labeled **Current motif** to choose another motif or **Create new item**.
**Esc** cancels the list and **Return** loads the selected item. Below the
current motif, **S** returns to the main score, **G** opens generation settings,
**V** opens the version picker, and **K** edits the key from the current bar.
The main score's Composer tile also has a **Key** button beside Motif editor
and Build score. Choose a scale and home note, then select **Set key**;
**Clear key** removes both choices from this bar onward. A key lasts until the
next different key or clear change, even across section boundaries. Repeating
the active key does not add another change. Key edits can be undone with **Ctrl+Z**.
**Esc** returns from the key tile, generation, or version settings to the motif
tile; from there it closes Composer and returns to the
Note tile.

In the main score's Composer tile, replace the help text with motif references
such as `A B A1 | A B1 A2`, then select **Build score** (**B**). `A` uses the
first section of motif A; `A1` uses its first version. Spaces separate
references without adding sheet content. `|` starts a new section. Other
characters, including newlines, are treated as spaces. Confirming replaces the
main score; **Ctrl+Z** restores the previous score. The pattern is saved with
the project, including drafts that have not been built. Open the main score's
Composer tile again to edit it or rebuild after changing a motif.

Move the cursor into the section to replace, then choose **Generate new** (**G**).
The panel shows the target section. Enter a length of 3–32 beats and a
count of 1–7 distinct pitches, choose a generator type, scale, and home note,
then select **Generate**. Random step makes the familiar weighted pitch moves.
Up wave and Down wave move through the chosen number of scale notes in a
repeating wave. Set the pitch count to seven to use the full scale.
Alternating low and high visits opposite ends of that note set in turn.
Skipping notes visits every other note, then fills in the notes between them.
The scale selector offers the seven diatonic modes; the home note
selector offers all twelve chromatic notes. With no previous motif, both
selectors start at Random. A generated motif saves both the selector choices
and the scale and home note it used. Its choices reappear when Composer opens.
A Random choice stays Random; **Shift+C** draws a different value for that
choice each time. When a random generator reproduces the previous music,
regeneration tries up to twelve draws for a different result. If no different
result appears, it keeps the final valid draw. Every selected
pitch sounds at least once. The pitch count cannot exceed the beat count. The
generator places at least one note per beat, using beat and half-beat notes.
It sets bars to the motif length for 3–6 beats. Longer motifs use 3–6 beats
per bar, minimizing unused beats and preferring a size near four on ties.
Random-step moves stay within three scale steps; moves of one or two steps
are the most likely. The ordered patterns can make larger leaps.
You can edit and lengthen notes afterward. Generation replaces or adds a motif
melody in the active motif score, and is undoable.

In the motif editor, press **Shift+C** to regenerate the section under the
cursor and any versions derived from it. On the original section, this draws
a new original motif with its saved length, pitch count, and selector choices.
On a generated section, it reruns that section's saved generator or
transformation. Other branches
keep their edits. A section added by hand has no generation settings and
cannot be regenerated. When regenerated sections contain additions such as
notes in other melodies, shared dynamics, or repeats, the status bar asks
before discarding them. The change can be undone.

**Derive version** (**V**) offers six transformations: keep the opening and change the
ending, change the last notes, transpose by scale steps, invert the pitch
contour, reverse the pitches, or move half-beat splits. Operations that need a
number ask for it next. Each version is appended as a new section in the same
motif item. For motif A, the original is A, the next section is A1, and the third is A2.
The picker and parameter panel show the selected source section and the new
target section before the version is created.
The two ending transformations make random pitch choices and use the same
bounded retry when regenerated.
After creating a version, the editor keeps its whole section in view when it
fits. If it extends past the visible bars, the end aligns with the right edge.
The section stores its algorithm, source section, and parameter. **Generate**
replaces the selected section and rebuilds versions derived from it. Edits made
directly to rebuilt sections are lost; unrelated sections and sections added
by hand are preserved.

## Chord generator

Press **O** on the score sheet to open chord settings. Select the Scale and
Home note buttons to choose from lists, as in the motif generator. Set 2–7 notes per chord,
1–16 chords, and an optional scale degree (1–7) and inversion (0 through one
less than the note count). `random` chooses a degree or inversion for each chord.
Each chord lasts for the current cursor length; a series places the next chord
when the previous one ends and stops at a section boundary. In empty space,
the cursor pitch anchors the highest tone in the nearest octave. Rerolls keep
that original anchor.
Select **Place chord(s)** to insert them; **Esc** closes the settings. Press
**Shift+O** over a generated chord to choose another voicing. It makes up to
twelve attempts to differ from the previous chord. At a position without a
generated chord, **Shift+O** places one using the last successful settings for
that motif or score section. If there are no saved settings there, it opens
the chord settings. Those choices are restored with the editor session.

In empty space, the highest tone goes in the current melody. If the melody
already has a note at the cursor, that note keeps its pitch and duration; the
generator adds compatible tones in separate reusable melodies. It may place
those tones above or below the fixed note. **Shift+O** changes only the added
tones, and reopening settings keeps the fixed note. A note outside the chosen
scale cannot be used as a fixed tone under those settings. Existing notes in
the chord melodies cannot be overwritten. Placing or changing a chord is
undoable with **Ctrl+Z**.

The **Place in** button switches between **Separate melodies** and **Current
melody**. Separate melodies is the default. Current melody stacks equal-length
tones at one onset. When replacing a chord there, the generator keeps its base
note and replaces its other tones. Reroll keeps the chosen placement mode.

The motif's scale and home note appear in the Bar title and are used as its
chord defaults. In the main score, the Bar title shows the current section's
scale and home note, such as `Bar 2 (Major G)`. A
built section starts with a key when all its referenced motif versions agree.
Key changes inside a motif are carried into the built score; the next motif
returns to its own key. If the references disagree, the built section starts
without a key; choose a scale and home note in the chord panel when placing a
chord there. Available scales are Major, Dorian, Phrygian,
Lydian, Mixolydian, Minor, and Locrian. Home notes are C through B, including
sharps.

## Selecting time

**Shift+Left/Right** extends a selection in the active melody.
**Ctrl+Shift+Left/Right** selects across all melodies in half-beat steps.
**Shift+Home/End** and **Ctrl+Shift+Home/End** extend those selections to the
visible edge. **Ctrl+A** selects the entire score.

With a selection, **Up/Down** transposes by a semitone and **Page Up/Page Down**
transposes by an octave. **Ctrl+C** copies; **Ctrl+V** replaces a selected span
or inserts at the cursor's left edge. A melody selection pastes into the active
melody; a whole-score selection keeps the melody numbers. The first **Delete** clears
selected notes, and a second **Delete** closes the empty time.

## Melodies and sound

Press **N** to add a melody. Melody settings choose its instrument, color,
volume, and mute state. New melodies use Acoustic Grand Piano at 50% volume.
The sound picker includes General MIDI instruments and
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

## Figures and tuplets

The Note tile's **Figure** field compresses a figure into one written note span.
Enter `+2+0` for three evenly timed notes: the marked pitch, a note two
semitones above it, and the marked pitch again. The two signed numbers give the
middle and last pitches relative to the first. Every nonempty Figure field
uses the square-ended special note body. An ornament reference is horizontal.
A tuplet is horizontal when its first and last pitches match, or a
parallelogram with vertical ends when they differ. A simple tuplet shows only
the middle offset inside the body.

Enter `OA` for an ornament based on motif A, or `TA` for a more complex
tuplet based on motif A. Motifs A–Z can be referenced. Their notes remain at
their actual pitches in the motif editor; the figure plays them relative to
the marked note, scaled to its written duration. Every reference uses the same
filled five-point star followed by its motif number: A is star 1, B is star 2,
and Z is star 26. Extra separators are accepted, such as `O:A`, `T:A`, and
`+2,-1`; the editor displays the compact form.
The PDF packs referenced motifs into appendix tiles, filling columns from top
to bottom before adding pages. If the score has one page and all tiles fit
below it, the appendix shares that page and keeps the score page header.
Otherwise appendix pages carry the same title, author, and catalog as score
pages, with Appendix - 1, Appendix - 2, and so on in place of the page number.
The star and number sit beside the middle of each figure. An ornament
panel has one
dashed line for the marked note pitch. The first motif note anchors that pitch.
Other notes keep their written lengths and note shapes at offsets from -2 to
+2 semitones; every note is hollow because its key color depends on the marked
note. A tuplet panel instead shows
the motif's original pitches and rhythms on only the octave rows it uses, in
black. A motif used both ways has both panels in its tile. An empty,
polyphonic, or nested motif is reported as an invalid figure. Figures replace
conflicting note marks such as trills and staccato.

## Bars, sections, and print

Bar settings hold its beat count, tempo, and optional note. A new bar inherits
beat count and tempo. Changing a bar's beat count moves its boundary without
moving notes or shared dynamic marks in time. Changing a tempo also updates
following bars that still have the previous tempo. Bar notes appear above
their bars in the editor and printed score.

Every score starts with a section. Use **|** or the Bar control to start a new
section at the current bar. A section can repeat up to eight extra times. Dots
above its last bar show the repeat count. A selection plays once, even if it
contains a repeated section.

**V** opens the PDF preview. **B/N** turns to the previous or next page;
**F/L** jumps to the first or last page. **V**, **Esc**, or **Return** closes it.
The PDF uses landscape A4 vector pages. It may show more octave bands than the
editor's four rows so that melodies in different ranges can appear together.
A dot marks the A3–G♯4 band containing middle C, and triangles mark higher or
lower bands.

## Files and settings

**Ctrl+S** opens Save, **Ctrl+W** overwrites the current project file, and
**Ctrl+O** opens Load. It can also import MusicXML files and warns about
unsupported features before importing. **New** in the Load panel
starts an empty, unnamed score. Use **Ctrl+S** to name it before **Ctrl+W** can
overwrite a file. With unsaved changes, Load asks for confirmation before
opening the file selector; that decision also covers New. After saving,
**Ctrl+E** opens the export list in the Note tile. Use **Space** to
toggle MIDI, MusicXML, PDF, and WAV, then choose
**Export selected** to write every selected format beside the project file.
WAV uses FluidSynth and a SoundFont when available, falling back to the built-in sound.
In the PDF preview, **U/J** increase/decrease note size and **H/K** decrease/increase
beat width in 5% steps, up to 200%. The PDF layout keeps a section on one line
when it fits; longer sections wrap between bars. Note size changes staff height
too. Increasing it
also increases beat width when needed; decreasing beat width reduces note size
when needed. Save the project to keep its PDF sizes with the project file.
PDF text keeps at least the page number's 32-unit print size (about 7.7 pt on
A4). Octave symbols are half a note head at normal size; as notes shrink, they
can grow relative to the note head but never exceed it.
When PDF note size is below 100%, note marks appear above their notes at the
same horizontal position. Fingering digits stay at the lower note corners.
When a key is active, an exclamation mark above the right corner identifies
each pitched note outside its scale. The mark is omitted when no key is active.

The current score, selection, visible bar position, and each score or motif's
cursor are restored from `~/.madscore/session.json` when the app starts again.
Switching between the score and motifs also returns to each item's last cursor
position. Settings stores its
default directory as it is edited. The first file selector starts there, or
in `~/Music` when no directory is set; later selectors remember the last
directory browsed. Press **F1** from the editor to open Settings and **F1**
there to open the offline [Tristaff tutorial](../Tristaff/index.html).
