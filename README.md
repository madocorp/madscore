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

The editor opens on the score. Bar, Melody, Note, Cursor, and Actions controls
sit below it; each control shows its shortcut. Arrow keys move through notes
and gaps, **Return** places or changes a note, and **Esc** moves between the
sheet and the controls.

- **P** plays the score; **M** plays the active melody. Playback starts at the
  cursor, or plays the selected span. It hides the edit cursor and follows each
  beat across the score. Press **Esc** or the same play
  key to stop; **P** and **M** also switch between score and melody playback.
- **Ctrl+S** saves a JSON score, **Ctrl+O** loads one, and **Ctrl+W** overwrites
  the current file. The Load panel also has **New** for an empty score; save it
  with **Ctrl+S** before using **Ctrl+W**. After saving, **Ctrl+E** exports PDF
  and **Ctrl+M** exports MIDI beside it.
- **V** opens the PDF preview. Use **+/-** to zoom, arrows to scroll, and **B/N**
  to turn pages. **F1** opens Settings, where you can choose the playback sound
  and the default file directory.
- **C** opens the Composer tile. Its buttons switch between the main score,
  motif, and phrase editors; each editor keeps its own notes.

The app restores your working score and position when it starts again. Load
[the example score](Assets/example.json) to explore its melodies, drums, and
notation marks. For more detail, see the [editor reference](Docs/editor.md).
For the notation itself, read the offline [Tristaff tutorial](Tristaff/index.html).

Saved JSON uses a project wrapper: `title`, `author`, `catalog`, and `items`.
The item with ID and type `main` is the finished score; motif and phrase items
can also be stored. Each item's `score` holds `bars`, `melodies`, `notes`, and
`scoreDynamics`. The session stores the project and active item alongside the
cursor and other editor state. Earlier bare-score JSON files and sessions are
not loaded.

## Tests

Run `php Tests/run.php` for the main score checks. Other focused checks are in
`Tests/`, for example `php Tests/Settings.php` and `php Tests/Midi.php`.

## License

madscore is released under the [Unlicense](UNLICENSE).
