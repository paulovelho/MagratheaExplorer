<?php

namespace MagratheaExplorer\Tests\File;

use Magrathea\Exceptions\MagratheaApiException;
use MagratheaExplorer\File\ImageProcessor;
use MagratheaExplorer\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 of the 1.5.0 plan: the conversion guard (4131) and the EXIF orientation fix (the
 * interim thumbnail guard went with the GD thumbnail in Phase 6). The guard reads headers
 * only, so its fixtures are hand-written headers with no pixel data; the orientation tests
 * need GD.
 */
class ImageProcessorTest extends TestCase {

	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach($this->tempFiles as $f) @unlink($f);
		TestConfig::Set();
	}

	// --- conversion guard -------------------------------------------------------------

	public function testConvertibleUnderBothLimits(): void {
		ImageProcessor::AssertConvertible($this->PngHeader(6000, 6000), "png"); // 36 MP
		$this->addToAssertionCount(1);
	}

	public function testRefusesOverPixelLimit(): void {
		$this->assertRefused($this->PngHeader(7000, 6000), "png", "7000x6000 px is 42 MP, the limit is 40 MP");
	}

	/** A decompression bomb is refused from its header, without decoding anything. */
	public function testRefusesBombHeader(): void {
		$this->assertRefused($this->PngHeader(100000, 100000), "png", "100000x100000 px is 10000 MP");
	}

	public function testRefusesOverByteLimit(): void {
		TestConfig::Set(["convert_max_bytes" => "1M"]);
		$path = $this->PngHeader(10, 10, (int)(1.5 * 1048576));
		$this->assertRefused($path, "png", "1.5 MB, the limit is 1 MB");
	}

	public function testLimitsComeFromConfig(): void {
		TestConfig::Set(["convert_max_pixels" => "100"]);
		$this->assertRefused($this->PngHeader(11, 10), "png", "the limit is 0 MP");
		ImageProcessor::AssertConvertible($this->PngHeader(10, 10), "png");
	}

	/** Orientations 5-8 turn 90° onto a second canvas: 1.5x the pixels. 1-4 flip in place. */
	#[RequiresPhpExtension("exif")]
	public function testTurnedJpegCountsOneAndAHalf(): void {
		$this->assertRefused($this->JpegHeader(5000, 6000, 6), "jpg", "5000x6000 px is 30 MP, 45 MP counting the 90° turn it needs, the limit is 40 MP");
		$this->assertRefused($this->JpegHeader(5000, 6000, 8), "jpg", "45 MP counting");
		foreach([1, 3, 4] as $orientation) {
			ImageProcessor::AssertConvertible($this->JpegHeader(5000, 6000, $orientation), "jpg");
		}
		ImageProcessor::AssertConvertible($this->JpegHeader(4000, 6000, 6), "jpg"); // 24 MP portrait: 36 MP counted
		$this->addToAssertionCount(1);
	}

	/** SVG has no pixel count: bytes only. */
	public function testSvgCheckedOnBytesOnly(): void {
		TestConfig::Set(["convert_max_bytes" => "1K"]);
		ImageProcessor::AssertConvertible($this->TempFile('<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="100000"/>'), "svg");
		$this->assertRefused($this->TempFile('<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat(" ", 2048).'</svg>'), "svg", "MB");
	}

	// --- EXIF orientation ------------------------------------------------------------

	/**
	 * Where a stored pixel (x, y) of a W×H image lands on display, per EXIF orientation,
	 * with the display size. Written from the EXIF definitions, independently of the code.
	 */
	private static function Display(int $orientation, int $x, int $y, int $w, int $h): array {
		return match($orientation) {
			1 => [$x, $y, $w, $h],
			2 => [$w - 1 - $x, $y, $w, $h],
			3 => [$w - 1 - $x, $h - 1 - $y, $w, $h],
			4 => [$x, $h - 1 - $y, $w, $h],
			5 => [$y, $x, $h, $w],
			6 => [$h - 1 - $y, $x, $h, $w],
			7 => [$h - 1 - $y, $w - 1 - $x, $h, $w],
			8 => [$y, $w - 1 - $x, $h, $w],
		};
	}

	public static function orientations(): array {
		return array_combine(array_map(fn($o) => "orientation ".$o, range(1, 8)), array_map(fn($o) => [$o], range(1, 8)));
	}

	#[DataProvider("orientations")]
	#[RequiresPhpExtension("gd")]
	#[RequiresPhpExtension("exif")]
	public function testLoadAppliesOrientation(int $orientation): void {
		$path = $this->QuadrantJpeg($orientation);
		$this->assertSame($orientation, ImageProcessor::ReadJpegOrientation($path));

		$gd = ImageProcessor::LoadGD($path, "jpg");
		$this->assertNotFalse($gd);
		[, , $dw, $dh] = self::Display($orientation, 0, 0, 80, 40);
		$this->assertSame([$dw, $dh], [imagesx($gd), imagesy($gd)]);
		foreach(self::QUADRANTS as [$x, $y, $rgb]) {
			[$dx, $dy] = self::Display($orientation, $x, $y, 80, 40);
			$this->assertColor($rgb, $gd, $dx, $dy, "stored ($x,$y) → display ($dx,$dy)");
		}
	}

	/** The non-verbatim re-encode writes display pixels and no EXIF: a portrait photo stays portrait. */
	#[RequiresPhpExtension("gd")]
	#[RequiresPhpExtension("exif")]
	public function testReencodeStoresRotatedPixels(): void {
		$path = $this->QuadrantJpeg(6);
		$out = ImageProcessor::ReencodeAndConvert($path, "jpg", false);
		$this->tempFiles[] = $out["path"];
		$this->assertSame("webp", $out["extension"]);
		$this->assertSame([40, 80], ImageProcessor::GetDimensions($out["path"], "webp"));
	}

	#[RequiresPhpExtension("exif")]
	public function testOrientationDefaultsToOne(): void {
		$this->assertSame(1, ImageProcessor::ReadJpegOrientation($this->TempFile("not a jpeg")));
	}

	// --- fixtures ----------------------------------------------------------------------

	/** 80×40, four 40×20 quadrants; [x, y, rgb] = each quadrant's center. */
	private const QUADRANTS = [
		[20, 10, [255, 0, 0]], [60, 10, [0, 255, 0]],
		[20, 30, [0, 0, 255]], [60, 30, [255, 255, 0]],
	];

	private function QuadrantJpeg(int $orientation): string {
		$gd = imagecreatetruecolor(80, 40);
		foreach(self::QUADRANTS as [$x, $y, $rgb]) {
			imagefilledrectangle($gd, $x - 20, $y - 10, $x + 19, $y + 9, imagecolorallocate($gd, ...$rgb));
		}
		ob_start();
		imagejpeg($gd, null, 95);
		$jpeg = ob_get_clean();
		// EXIF APP1 right after SOI: one IFD0 entry, Orientation (0x0112, SHORT)
		$tiff = "II".pack("v", 42).pack("V", 8).pack("v", 1)
			.pack("v", 0x0112).pack("v", 3).pack("V", 1).pack("v", $orientation).pack("v", 0)
			.pack("V", 0);
		$app1 = "Exif\0\0".$tiff;
		return $this->TempFile("\xFF\xD8\xFF\xE1".pack("n", strlen($app1) + 2).$app1.substr($jpeg, 2));
	}

	/**
	 * SOI, EXIF APP1 (Orientation), SOF0 claiming $w×$h, an empty SOS, EOI: getimagesize() and
	 * exif_read_data() read it (exif needs the SOS), nothing can decode it.
	 */
	private function JpegHeader(int $w, int $h, int $orientation): string {
		$tiff = "II".pack("v", 42).pack("V", 8).pack("v", 1)
			.pack("v", 0x0112).pack("v", 3).pack("V", 1).pack("v", $orientation).pack("v", 0)
			.pack("V", 0);
		$app1 = "Exif\0\0".$tiff;
		$sof = pack("C", 8).pack("n", $h).pack("n", $w)."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01";
		$sos = "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00";
		return $this->TempFile("\xFF\xD8"
			."\xFF\xE1".pack("n", strlen($app1) + 2).$app1
			."\xFF\xC0".pack("n", strlen($sof) + 2).$sof
			."\xFF\xDA".pack("n", strlen($sos) + 2).$sos
			."\xFF\xD9");
	}

	/** A PNG signature + IHDR claiming $w×$h, then IEND: getimagesize() reads it, nothing can decode it. */
	private function PngHeader(int $w, int $h, int $padTo = 0): string {
		$chunk = fn(string $type, string $data) => pack("N", strlen($data)).$type.$data.pack("N", crc32($type.$data));
		$png = "\x89PNG\r\n\x1a\n".$chunk("IHDR", pack("NNCCCCC", $w, $h, 8, 6, 0, 0, 0)).$chunk("IEND", "");
		return $this->TempFile(str_pad($png, $padTo, "\0"));
	}

	private function TempFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), "explorer_test_");
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

	private function assertRefused(string $path, string $extension, string $detail): void {
		try {
			ImageProcessor::AssertConvertible($path, $extension);
			$this->fail("expected 4131");
		} catch(MagratheaApiException $ex) {
			$this->assertSame(4131, $ex->getCode());
			$this->assertSame(413, intval($ex->getCode() / 10), "HTTP status");
			$this->assertStringContainsString("verbatim=1", $ex->getMessage());
			$this->assertStringContainsString($detail, $ex->getMessage());
		}
	}

	/** JPEG is lossy: each channel within 48 of the expected value. */
	private function assertColor(array $rgb, \GdImage $gd, int $x, int $y, string $where): void {
		$c = imagecolorat($gd, $x, $y);
		$actual = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
		foreach($rgb as $i => $v) {
			$this->assertEqualsWithDelta($v, $actual[$i], 48, $where.": got rgb(".implode(",", $actual).")");
		}
	}

}
