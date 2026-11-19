<?php

namespace MagratheaExplorer\File;

use MagratheaExplorer\ApiTime;
use MagratheaExplorer\Folder\FolderControl;
use MagratheaExplorer\Processing\RenditionProfile;
use MagratheaExplorer\Storage\StorageAdapter;
use MagratheaExplorer\Storage\StorageFactory;

/**
 * The File as the API shows it: the owner's view (FileApi) and the public share's subset
 * (PublicShareApi) are built here, so `thumbnail_url` and the size fields can't drift apart.
 *
 * - `properties`: the processor's, merged over the top-level `width`/`height`/`duration`, so a
 *   file never processed still gets what's known (a partial object, never null). A key that's
 *   absent was never computed; a null one was computed and isn't known (a DPI the file doesn't
 *   declare).
 * - `renditions`: null while `pending` (the next job replaces them, with new URLs), `{}` when
 *   there are none, else `{name: {url, width, height, mime_type, size}}`.
 * - `thumbnail_url`: the `thumb` rendition's URL; null while pending or without one.
 */
final class FileResponse {

	/**
	 * @param ?string $folderUuid when the caller already has it; else looked up
	 * @param ?array<string, FileRendition> $renditions preloaded (FileRenditionControl::GetForFiles()); null = loaded here
	 * @param ?string[] $tags tag names, preloaded (FileTagControl::GetTagNamesForFiles()); null = loaded here
	 */
	public static function Owner(File $file, ?string $folderUuid = null, ?array $renditions = null, ?array $tags = null): array {
		$storage = StorageFactory::Instance()->Get();
		$renditions ??= FileRenditionControl::GetForFile((int)$file->id);
		return [
			"uuid" => $file->uuid,
			"folder_uuid" => $folderUuid ?? FolderControl::UuidFor($file->folder_id),
			"name" => $file->name,
			"extension" => $file->extension,
			"mime_type" => $file->mime_type,
			"file_type" => $file->file_type,
			"size" => (int)$file->size,
			"sha256" => $file->sha256,
			"width" => $file->width !== null ? (int)$file->width : null,
			"height" => $file->height !== null ? (int)$file->height : null,
			"duration" => $file->duration !== null ? (int)$file->duration : null,
			"no_convert" => (bool)$file->no_convert,
			"verbatim" => (bool)$file->verbatim,
			"properties" => (object)self::Properties($file),
			"processing" => [
				"status" => self::Status($file),
				"error" => $file->processing_error !== null && $file->processing_error !== "" ? (string)$file->processing_error : null,
			],
			"url" => $storage->url($file->storage_path, $file->name, $file->DispositionType()),
			"renditions" => self::Renditions($file, $renditions, $storage),
			"thumbnail_url" => self::ThumbnailUrl($file, $renditions, $storage),
			"tags" => $tags ?? array_map(fn($t) => $t->name, FileTagControl::GetTagsForFile((int)$file->id)),
			"created_at" => ApiTime::Utc($file->created_at),
		];
	}

	/**
	 * A strict subset of Owner() -- same field names and types, so the app can render a shared
	 * listing with the shape it already knows. Left out on purpose: `folder_uuid` (the visitor
	 * navigates by share, not by folder), `no_convert`, `verbatim` and `tags` (owner-side
	 * metadata), and `sha256`, `properties`, `processing` and `renditions` (decided: a share
	 * shows the thumbnail only, through `thumbnail_url`).
	 *
	 * `url` is the same direct-from-storage link the owner gets. Deleting the share stops the
	 * share page; a storage URL someone already copied keeps working, and only deleting the file
	 * stops that.
	 * @param ?array<string, FileRendition> $renditions preloaded; null = loaded here
	 */
	public static function Shared(File $file, ?array $renditions = null): array {
		$storage = StorageFactory::Instance()->Get();
		$renditions ??= FileRenditionControl::GetForFile((int)$file->id);
		return [
			"uuid" => $file->uuid,
			"name" => $file->name,
			"extension" => $file->extension,
			"mime_type" => $file->mime_type,
			"file_type" => $file->file_type,
			"size" => (int)$file->size,
			"width" => $file->width !== null ? (int)$file->width : null,
			"height" => $file->height !== null ? (int)$file->height : null,
			"duration" => $file->duration !== null ? (int)$file->duration : null,
			"url" => $storage->url($file->storage_path, $file->name, $file->DispositionType()),
			"thumbnail_url" => self::ThumbnailUrl($file, $renditions, $storage),
			"created_at" => ApiTime::Utc($file->created_at),
		];
	}

	public static function Status(File $file): string {
		return in_array($file->processing_status, ["none", "pending", "ready", "failed"], true) ? $file->processing_status : "none";
	}

	/** The top-level values that are known, with the stored properties over them. */
	public static function Properties(File $file): array {
		$properties = [];
		foreach(["width", "height", "duration"] as $name) {
			if($file->$name !== null) $properties[$name] = (int)$file->$name;
		}
		foreach($file->StoredProperties() ?? [] as $name => $value) {
			// a value the processor couldn't read doesn't hide one the upload did
			if($value !== null || !array_key_exists($name, $properties)) $properties[(string)$name] = $value;
		}
		return $properties;
	}

	/** @param array<string, FileRendition> $renditions */
	private static function Renditions(File $file, array $renditions, StorageAdapter $storage): ?object {
		if(self::Status($file) === "pending") return null;
		$view = [];
		foreach($renditions as $name => $rendition) {
			$view[(string)$name] = [
				"url" => $storage->url($rendition->storage_path),
				"width" => $rendition->width !== null ? (int)$rendition->width : null,
				"height" => $rendition->height !== null ? (int)$rendition->height : null,
				"mime_type" => $rendition->mime_type,
				"size" => $rendition->size !== null ? (int)$rendition->size : null,
			];
		}
		// `{}` when empty, never `[]`
		return (object)$view;
	}

	/** @param array<string, FileRendition> $renditions */
	private static function ThumbnailUrl(File $file, array $renditions, StorageAdapter $storage): ?string {
		if(self::Status($file) === "pending") return null;
		$thumb = $renditions[RenditionProfile::THUMB] ?? null;
		return $thumb !== null ? $storage->url($thumb->storage_path) : null;
	}

}
