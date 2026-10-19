<?php

namespace MADSCORE\Score;

/** Stable General MIDI choices with approximate built-in playback sounds. */
final class Sound {

  private const PROGRAM_GROUPS = [
    'Piano' => 'Acoustic Grand Piano|Bright Acoustic Piano|Electric Grand Piano|Honky-tonk Piano|Electric Piano 1|Electric Piano 2|Harpsichord|Clavinet',
    'Chromatic' => 'Celesta|Glockenspiel|Music Box|Vibraphone|Marimba|Xylophone|Tubular Bells|Dulcimer',
    'Organ' => 'Drawbar Organ|Percussive Organ|Rock Organ|Church Organ|Reed Organ|Accordion|Harmonica|Tango Accordion',
    'Guitar' => 'Nylon Guitar|Steel Guitar|Jazz Guitar|Clean Guitar|Muted Guitar|Overdriven Guitar|Distortion Guitar|Guitar Harmonics',
    'Bass' => 'Acoustic Bass|Finger Bass|Pick Bass|Fretless Bass|Slap Bass 1|Slap Bass 2|Synth Bass 1|Synth Bass 2',
    'Strings' => 'Violin|Viola|Cello|Contrabass|Tremolo Strings|Pizzicato Strings|Orchestral Harp|Timpani',
    'Ensemble' => 'String Ensemble 1|String Ensemble 2|Synth Strings 1|Synth Strings 2|Choir Aahs|Voice Oohs|Synth Voice|Orchestra Hit',
    'Brass' => 'Trumpet|Trombone|Tuba|Muted Trumpet|French Horn|Brass Section|Synth Brass 1|Synth Brass 2',
    'Reed' => 'Soprano Sax|Alto Sax|Tenor Sax|Baritone Sax|Oboe|English Horn|Bassoon|Clarinet',
    'Pipe' => 'Piccolo|Flute|Recorder|Pan Flute|Blown Bottle|Shakuhachi|Whistle|Ocarina',
    'Synth Lead' => 'Square Lead|Sawtooth Lead|Calliope Lead|Chiff Lead|Charang Lead|Voice Lead|Fifths Lead|Bass + Lead',
    'Synth Pad' => 'New Age Pad|Warm Pad|Polysynth Pad|Choir Pad|Bowed Pad|Metallic Pad|Halo Pad|Sweep Pad',
    'Synth FX' => 'Rain FX|Soundtrack FX|Crystal FX|Atmosphere FX|Brightness FX|Goblins FX|Echoes FX|Sci-fi FX',
    'Ethnic' => 'Sitar|Banjo|Shamisen|Koto|Kalimba|Bag Pipe|Fiddle|Shanai',
    'Percussive' => 'Tinkle Bell|Agogo|Steel Drums|Woodblock|Taiko Drum|Melodic Tom|Synth Drum|Reverse Cymbal',
    'Effects' => 'Guitar Fret Noise|Breath Noise|Seashore|Bird Tweet|Telephone Ring|Helicopter|Applause|Gunshot',
  ];

  private const DRUM_NAMES = [
    35 => 'Acoustic Bass Drum', 36 => 'Bass Drum', 37 => 'Side Stick', 38 => 'Acoustic Snare',
    39 => 'Hand Clap', 40 => 'Electric Snare', 41 => 'Low Floor Tom', 42 => 'Closed Hi-Hat',
    43 => 'High Floor Tom', 44 => 'Pedal Hi-Hat', 45 => 'Low Tom', 46 => 'Open Hi-Hat',
    47 => 'Low-Mid Tom', 48 => 'Hi-Mid Tom', 49 => 'Crash Cymbal 1', 50 => 'High Tom',
    51 => 'Ride Cymbal 1', 52 => 'Chinese Cymbal', 53 => 'Ride Bell', 54 => 'Tambourine',
    55 => 'Splash Cymbal', 56 => 'Cowbell', 57 => 'Crash Cymbal 2', 58 => 'Vibraslap',
    59 => 'Ride Cymbal 2', 60 => 'Hi Bongo', 61 => 'Low Bongo', 62 => 'Mute Hi Conga',
    63 => 'Open Hi Conga', 64 => 'Low Conga', 65 => 'High Timbale', 66 => 'Low Timbale',
    67 => 'High Agogo', 68 => 'Low Agogo', 69 => 'Cabasa', 70 => 'Maracas',
    71 => 'Short Whistle', 72 => 'Long Whistle', 73 => 'Short Guiro', 74 => 'Long Guiro',
    75 => 'Claves', 76 => 'High Wood Block', 77 => 'Low Wood Block', 78 => 'Mute Cuica',
    79 => 'Open Cuica', 80 => 'Mute Triangle', 81 => 'Open Triangle',
  ];

  private const LEGACY = [
    'organ' => 'gm:19', 'sine' => 'gm:78', 'triangle' => 'gm:10',
    'square' => 'gm:80', 'sawtooth' => 'gm:81',
    'kick' => 'drum:36', 'snare' => 'drum:38', 'clap' => 'drum:39',
    'hat' => 'drum:42', 'open_hat' => 'drum:46',
  ];

  public static function legacyInstrument(string $pattern): string {
    return self::LEGACY[$pattern] ?? throw new \DomainException('Unknown sound pattern.');
  }

  public static function valid(string $instrument): bool {
    return self::program($instrument) !== null || self::drumKey($instrument) !== null;
  }

  public static function program(string $instrument): ?int {
    if (!preg_match('/^gm:(0|[1-9][0-9]*)$/D', $instrument, $match)) {
      return null;
    }
    $program = (int)$match[1];
    return $program <= 127 ? $program : null;
  }

  public static function drumKey(string $instrument): ?int {
    if (!preg_match('/^drum:([1-9][0-9]*)$/D', $instrument, $match)) {
      return null;
    }
    $key = (int)$match[1];
    return isset(self::DRUM_NAMES[$key]) ? $key : null;
  }

  /** A single picker includes melodic programs and percussion sounds. */
  public static function items(): array {
    $items = [];
    $program = 0;
    foreach (self::PROGRAM_GROUPS as $family => $names) {
      foreach (explode('|', $names) as $name) {
        $items[] = ['value' => 'gm:' . $program++, 'label' => $family . ': ' . $name,
          'searchOffset' => mb_strlen($family) + 2];
      }
    }
    foreach (self::DRUM_NAMES as $key => $name) {
      $items[] = ['value' => 'drum:' . $key, 'label' => 'Drums: ' . $name, 'searchOffset' => 7];
    }
    return $items;
  }

  public static function label(string $instrument): string {
    foreach (self::items() as $item) {
      if ($item['value'] === $instrument) {
        return substr($item['label'], strpos($item['label'], ': ') + 2);
      }
    }
    throw new \DomainException('Unknown instrument.');
  }

  /** Pick a rough waveform or rhythm sound for machines without MIDI playback. */
  public static function fallback(string $instrument): string {
    if (($key = self::drumKey($instrument)) !== null) {
      if (in_array($key, [35, 36, 41, 43, 45, 47, 48, 50, 60, 61, 62, 63, 64, 65, 66], true)) {
        return 'kick';
      }
      if (in_array($key, [38, 40], true)) {
        return 'snare';
      }
      if (in_array($key, [37, 39, 58, 75, 76, 77, 78, 79], true)) {
        return 'clap';
      }
      return in_array($key, [46, 49, 51, 52, 55, 57, 59, 72, 74, 81], true) ? 'open_hat' : 'hat';
    }
    $program = self::program($instrument);
    if ($program === null) {
      throw new \DomainException('Unknown instrument.');
    }
    if ($program >= 16 && $program <= 23) {
      return 'organ';
    }
    if (in_array($program, [78, 72, 73, 74, 75, 79], true) || $program >= 96 && $program <= 103) {
      return 'sine';
    }
    if ($program === 80 || $program >= 84 && $program <= 87) {
      return 'square';
    }
    if ($program === 81 || $program >= 32 && $program <= 71 || $program >= 88 && $program <= 95) {
      return 'sawtooth';
    }
    return 'triangle';
  }
}
