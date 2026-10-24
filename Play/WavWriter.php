<?php

namespace MADSCORE\Play;

use MADSCORE\Score\Score;

/** Export SoundFont MIDI audio when available, otherwise use the built-in sound. */
final class WavWriter {

  public static function write(string $path, Score $score): void {
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $midiWave = null;
    if (MidiPlayback::unavailableReason() === null) {
      try {
        $midiWave = MidiPlayback::render($score);
      } catch (\Throwable) {
        // Match playback: use built-in sound if MIDI rendering fails.
      }
    }
    if ($midiWave !== null) {
      try {
        if (!copy($midiWave, $temp) || !rename($temp, $path)) {
          throw new \RuntimeException('Could not export WAV: ' . $path);
        }
        return;
      } finally {
        unlink($midiWave);
        if (is_file($temp)) {
          unlink($temp);
        }
      }
    }
    $source = new ScoreSource($score);
    $file = fopen($temp, 'wb');
    if ($file === false) {
      throw new \RuntimeException('Could not export WAV: ' . $path);
    }
    try {
      self::writeBytes($file, str_repeat("\0", 44));
      $dataBytes = 0;
      while (($floats = $source->read(4096)) !== '') {
        $samples = unpack('g*', $floats);
        $pcm = '';
        foreach ($samples as $sample) {
          $value = max(-32768, min(32767, (int)round($sample * 32768)));
          $pcm .= pack('v', $value & 0xffff);
        }
        $dataBytes += strlen($pcm);
        if ($dataBytes > 0xffffffff - 36) {
          throw new \RuntimeException('WAV audio exceeds the 4 GB format limit.');
        }
        self::writeBytes($file, $pcm);
      }
      $rate = ScoreSource::SAMPLE_RATE;
      $header = 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', $dataBytes);
      if (fseek($file, 0) !== 0) {
        throw new \RuntimeException('Could not finish WAV header.');
      }
      self::writeBytes($file, $header);
      if (!fclose($file)) {
        $file = null;
        throw new \RuntimeException('Could not finish WAV file.');
      }
      $file = null;
      if (!rename($temp, $path)) {
        throw new \RuntimeException('Could not export WAV: ' . $path);
      }
    } finally {
      if (is_resource($file)) {
        fclose($file);
      }
      if (is_file($temp)) {
        unlink($temp);
      }
    }
  }

  private static function writeBytes($file, string $bytes): void {
    $offset = 0;
    while ($offset < strlen($bytes)) {
      $written = fwrite($file, substr($bytes, $offset));
      if ($written === false || $written === 0) {
        throw new \RuntimeException('Could not write WAV audio.');
      }
      $offset += $written;
    }
  }
}
