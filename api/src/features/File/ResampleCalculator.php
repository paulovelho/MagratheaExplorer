<?php

namespace MagratheaExplorer\File;

use Magrathea2\Exceptions\MagratheaException;

/**
 * Ported near-verbatim from MagratheaImages3's ResampleCalculator.php. Used here only
 * for the fixed-size square crop-to-fill thumbnail (Images3 also uses it for arbitrary
 * on-demand WxH resizing, which this project doesn't need).
 */
class ResampleCalculator {

	public bool $keepAspectRatio = true;
	public int $original_image_width;
	public int $original_image_height;
	public int $final_image_width;
	public int $final_image_height;
	public array $src_aspect;
	public array $dst_aspect;

	public bool $shouldResize = false;
	public float $resizeRatio;
	public int $resize_width;
	public int $resize_height;

	public int $src_width;
	public int $src_height;
	public int $dst_width;
	public int $dst_height;
	public int $src_x;
	public int $src_y;
	public int $dst_x;
	public int $dst_y;

	public function __construct(
		int $src_width, int $src_height,
		int $dest_width, int $dest_height
	) {
		$this->original_image_width = $src_width;
		$this->original_image_height = $src_height;
		$this->final_image_width = $dest_width;
		$this->final_image_height = $dest_height;
		$this->Initialize();
	}

	public function Initialize() {
		$this->src_aspect = $this->BuildAspectRatio($this->original_image_width, $this->original_image_height);
		$this->dst_aspect = $this->BuildAspectRatio($this->final_image_width, $this->final_image_height);
		$this->src_width = $this->original_image_width;
		$this->src_height = $this->original_image_height;
		$this->dst_width = $this->final_image_width;
		$this->dst_height = $this->final_image_height;
	}

	public function BuildAspectRatio(int $w, int $h): array {
		$aspectRatio = $w / $h;
		$format = ($aspectRatio == 1 ? "square" : (
			$aspectRatio > 1 ? "landscape" : "portrait"
		));
		return [
			"ratio" => $aspectRatio,
			"format" => $format,
		];
	}

	public function ZeroPoints() {
		$this->dst_x = $this->dst_y = $this->src_x = $this->src_y = 0;
	}

	public function Calculate(): array {
		if( !$this->keepAspectRatio || $this->dst_aspect["ratio"] == $this->src_aspect["ratio"] ) {
			return $this->SameAspectRatio();
		}
		if( $this->dst_aspect["ratio"] > $this->src_aspect["ratio"] ) {
			return $this->CutHorizontal();
		}
		if( $this->dst_aspect["ratio"] < $this->src_aspect["ratio"] ) {
			return $this->CutVertical();
		}
		throw new MagratheaException("invalid aspect ratios", 500);
	}

	public function SameAspectRatio(): array {
		$this->ZeroPoints();
		return $this->returnData();
	}
	public function CutHorizontal(): array {
		$this->ZeroPoints();
		$this->resizeRatio = $this->final_image_width / $this->original_image_width;

		$this->dst_width = $this->final_image_width;
		$this->dst_height = $this->final_image_height;
		$this->src_height = (int)round($this->final_image_height / $this->resizeRatio);
		return $this->returnData();
	}
	public function CutVertical(): array {
		$this->ZeroPoints();
		$width = $this->original_image_width;
		$this->resizeRatio = $this->final_image_height / $this->original_image_height;
		if($this->resizeRatio != $this->src_aspect["ratio"]) {
			$this->resize_height = $this->final_image_height;
			$this->resize_width = intval(round($this->original_image_width * $this->resizeRatio));
			if(
				$this->resize_width != $this->original_image_width &&
				$this->resize_height != $this->original_image_height
			) {
				$this->shouldResize = true;
				$width = $this->resize_width;
			} else {
				$this->src_width = $this->final_image_width;
			}
		}

		// reposition initial point:
		$this->src_x = (int)round(($width - $this->final_image_width) / 2);
		$this->dst_width = $this->final_image_width;
		$this->dst_height = $this->final_image_height;
		return $this->returnData();
	}

	public function returnData(): array {
		$rs = [
			"dst_x" => $this->dst_x,
			"dst_y" => $this->dst_y,
			"src_x" => $this->src_x,
			"src_y" => $this->src_y,
			"dst_width" => $this->dst_width,
			"dst_height" => $this->dst_height,
			"src_width" => $this->src_width,
			"src_height" => $this->src_height,
		];
		if($this->shouldResize) {
			$rs["resize"] = true;
			$rs["resize_w"] = $this->resize_width;
			$rs["resize_h"] = $this->resize_height;
		} else $rs["resize"] = false;
		return $rs;
	}

}
