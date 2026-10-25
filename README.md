# madscore

madscore is an SPTK score editor for the Tristaff notation method.

## Requirements

- Linux with a graphical desktop session and PHP 8.2 or newer on the command
  line. Enable FFI, DOM, XMLReader, mbstring, PCNTL, and ZIP. GD is needed for non-PNG
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
php -m | grep -Ei '^(FFI|dom|xmlreader|mbstring|pcntl|zip|gd)$'
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

The editor opens on the score. Bar, Melody, Note, Cursor, and Actions controls
sit below it; each control shows its shortcut. Arrow keys move through notes
and gaps, **Return** places or changes a note, and **Esc** moves between the
sheet and the controls.

- **P** plays the score; **M** plays the active melody. Playback starts at the
  cursor, or plays the selected span. It hides the edit cursor and follows each
  beat across the score. Press **Esc** or the same play
  key to stop; **P** and **M** also switch between score and melody playback.
- **Ctrl+S** saves a compressed `.json.gz` project, **Ctrl+O** loads one, and
  **Ctrl+W** overwrites the current file. The Load panel also has **New** for an
  empty score; save it with **Ctrl+S** before using **Ctrl+W**. After saving,
  **Ctrl+E** opens the export list. Use **Space** to select MIDI, MusicXML, PDF, and WAV, then
  choose **Export selected** to write those files beside the saved project.
  WAV uses FluidSynth and a SoundFont when available, falling back to the built-in sound.
  MusicXML exports visible pitched melodies with notes, rests, and key signatures,
  choosing treble or bass clef for each melody's range.
  The Load panel also imports `.musicxml`, `.xml`, and compressed `.mxl` files as
  new unnamed scores. Basic import preserves pitches, chords, rests, ties, keys,
  meter, and bar-aligned tempo changes. It reports omitted notation marks before
  replacing the current score. Dense rhythms that exceed MadScore's bar or note
  limits are reported rather than shortened.
- **V** opens the PDF preview. Use **+/-** to zoom, arrows to scroll, and **B/N**
  to turn pages. In the preview, **U/J** increase/decrease PDF note size and
  **H/K** decrease/increase beat width in 5% steps. Note size also changes staff height;
  beat width stays at least as large as a note. These print settings are remembered.
  PDF layout keeps a section on one line when it fits. **F1** opens Settings,
  where you can choose the playback sound and the default file directory.
- **C** opens the Composer tile. In the main score, enter a motif pattern and
  select **Build score** to replace the score after confirmation. **M** opens
  the motif editor. There, **S** returns to the score, **G** opens generation
  settings, and **V** opens version settings.
- **O** opens chord settings in either the score or a motif. An existing melody
  note stays fixed while the generator adds compatible tones; in empty space,
  it makes a free chord. **Shift+O** changes a generated chord at the cursor,
  or places one with the previous settings. Scale and home note are stored per
  motif or score section. Choose whether to place the tones in separate
  melodies or together in the current melody.

The app restores your working score and position when it starts again. Load
[the example score](Assets/example.json) to explore its melodies, drums, and
notation marks. For more detail, see the [editor reference](Docs/editor.md).
For the notation itself, read the offline [Tristaff tutorial](Tristaff/index.html).

The JSON inside a saved project uses a wrapper: `title`, `author`, `catalog`, and `items`.
The item with ID `main` is the finished score; uppercase IDs identify motifs.
Each item's `score` holds `bars`, `melodies`, `notes`, and
`scoreDynamics`. The session stores the project and active item alongside the
cursor and other editor state. Earlier bare-score JSON files and sessions are
not loaded.
Existing plain `.json` projects still open and keep their format with **Ctrl+W**;
use **Ctrl+S** to save a compressed copy. The included example remains plain
JSON.

## Tests

Run `php Tests/run.php` for the main score checks. Other focused checks are in
`Tests/`, for example `php Tests/Settings.php` and `php Tests/Midi.php`.
Run `php Tests/MusicXml.php` for MusicXML content checks. If LilyPond is installed,
run `php Tests/MusicXmlInterop.php` to convert and compile an exported score with
`musicxml2ly` and `lilypond`.
Run `php Tests/MusicXmlImport.php` for MusicXML import and timing checks.

## License

madscore is released under the [Unlicense](UNLICENSE).
