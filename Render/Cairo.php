<?php

namespace MADSCORE\Render;

use SPTK\App;
use SPTK\Core\RasterImage;
use SPTK\SDLWrapper\SDL;

/** Loads Cairo and converts its image surfaces into SPTK textures. */
final class Cairo {

  private const SDL_PIXELFORMAT_ARGB8888 = 0x16362004;
  private static ?\FFI $library = null;
  private static ?SDL $standaloneSDL = null;

  /** Return the shared Cairo C API binding. */
  public static function library(): \FFI {
    if (self::$library === null) {
      try {
        self::$library = \FFI::cdef(file_get_contents(__DIR__ . '/cairo.h'), 'libcairo.so.2');
      } catch (\FFI\Exception $error) {
        throw new \RuntimeException('madscore needs the Cairo runtime library (libcairo.so.2).', 0, $error);
      }
    }
    return self::$library;
  }

  /** Copy an opaque Cairo image surface to SPTK's packed pixel format. */
  public static function raster(\FFI\CData $surface, int $width, int $height): RasterImage {
    $cairo = self::library();
    $cairo->cairo_surface_flush($surface);
    self::check($surface);
    $source = $cairo->cairo_image_surface_get_data($surface);
    $stride = $cairo->cairo_image_surface_get_stride($surface);
    $sdl = App::sdl() ?? (self::$standaloneSDL ??= new SDL());
    $size = $width * $height * 4;
    $pixels = $sdl->ffi->new('char[' . $size . ']');
    $success = $sdl->ffi->SDL_ConvertPixels($width, $height, self::SDL_PIXELFORMAT_ARGB8888, $source, $stride, SDL::SDL_PIXELFORMAT_RGBA8888, $pixels, $width * 4);
    $sdl->checkReturnValue($success, 'SDL_ConvertPixels');
    return new RasterImage($width, $height, \FFI::string($pixels, $size));
  }

  /** Reject a failed native drawing surface. */
  public static function check(\FFI\CData $surface): void {
    $cairo = self::library();
    $status = $cairo->cairo_surface_status($surface);
    if ($status !== 0) {
      throw new \RuntimeException('Cairo surface error: ' . \FFI::string($cairo->cairo_status_to_string($status)));
    }
  }
}
