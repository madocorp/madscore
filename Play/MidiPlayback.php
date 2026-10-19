<?php

namespace MADSCORE\Play;

use MADSCORE\Score\Score;

/** Render a MIDI range to a temporary WAV for SPTK's music player. */
final class MidiPlayback {

  /** Return a missing dependency message, or null when MIDI rendering is available. */
  public static function unavailableReason(): ?string {
    if (self::executable() === null) {
      return 'MIDI playback needs the fluidsynth command.';
    }
    if (self::soundfont() === null) {
      return 'MIDI playback needs a SoundFont (.sf2).';
    }
    return null;
  }

  public static function render(Score $score, ?int $melody = null, ?int $start = null, ?int $end = null): string {
    if (($reason = self::unavailableReason()) !== null) {
      throw new \RuntimeException($reason);
    }
    $base = tempnam(sys_get_temp_dir(), 'madscore-midi-');
    if ($base === false) {
      throw new \RuntimeException('Could not create a temporary MIDI file.');
    }
    unlink($base);
    $midi = $base . '.mid';
    $wave = $base . '.wav';
    try {
      $data = MidiWriter::encode($score, $melody, $start, $end);
      if (file_put_contents($midi, $data) !== strlen($data)) {
        throw new \RuntimeException('Could not write temporary MIDI.');
      }
      $process = proc_open([self::executable(), '-ni', '-g', '6', '-F', $wave, '-r', '22050', self::soundfont(), $midi],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      if (!is_resource($process)) {
        throw new \RuntimeException('Could not start FluidSynth.');
      }
      fclose($pipes[0]);
      stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      $errors = stream_get_contents($pipes[2]);
      fclose($pipes[2]);
      if (proc_close($process) !== 0 || !is_file($wave) || filesize($wave) < 44) {
        throw new \RuntimeException('FluidSynth failed: ' . trim(preg_replace('/\s+/', ' ', $errors)));
      }
      return $wave;
    } catch (\Throwable $error) {
      if (is_file($wave)) {
        unlink($wave);
      }
      throw $error;
    } finally {
      if (is_file($midi)) {
        unlink($midi);
      }
    }
  }

  private static function executable(): ?string {
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
      $candidate = rtrim($directory, '/') . '/fluidsynth';
      if (is_file($candidate) && is_executable($candidate)) {
        return $candidate;
      }
    }
    return null;
  }

  private static function soundfont(): ?string {
    $override = getenv('MADSCORE_SOUNDFONT');
    if (is_string($override) && $override !== '' && is_readable($override)) {
      return $override;
    }
    foreach (['/usr/share/sounds/sf2/*.sf2', '/usr/share/soundfonts/*.sf2'] as $pattern) {
      foreach (glob($pattern) ?: [] as $path) {
        if (is_readable($path)) {
          return $path;
        }
      }
    }
    return null;
  }
}
