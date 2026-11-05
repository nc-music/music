<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

namespace OCA\Music\Service;

class ArtistMetadataServiceTest extends \PHPUnit\Framework\TestCase {
	private const MBID = 'cc197bad-dc9c-440d-a5b5-d52ba2e14234';

	public function testAudioDbSearchRequiresAnExactMusicBrainzMatch() : void {
		$results = ['artists' => [
			['idArtist' => '100', 'strArtist' => 'Coldplay', 'strMusicBrainzID' => '00000000-0000-0000-0000-000000000000'],
			['idArtist' => '111239', 'strArtist' => 'Coldplay', 'strMusicBrainzID' => \strtoupper(self::MBID)],
		]];
		$match = ArtistMetadataService::selectAudioDbArtist($results, self::MBID);
		$this->assertSame('111239', $match['idArtist']);
	}

	public function testUnverifiedNameOrNonNumericIdIsRejected() : void {
		$this->assertNull(ArtistMetadataService::selectAudioDbArtist([
			'artists' => [['strArtist' => 'Coldplay', 'idArtist' => '111239']],
		], self::MBID));
		$this->assertNull(ArtistMetadataService::selectAudioDbArtist([
			'artists' => [['strMusicBrainzID' => self::MBID, 'idArtist' => 'not-a-number']],
		], self::MBID));
	}

	public function testAudioDbPhotoBiographyAndNumericId() : void {
		$info = ArtistMetadataService::extractAudioDbMetadata([
			'idArtist' => '111239',
			'strBiographyEN' => 'British rock band',
			'strArtistThumb' => 'https://www.theaudiodb.com/images/media/artist/thumb/test.jpg',
		]);
		$this->assertSame('111239', $info['audiodb_id']);
		$this->assertSame('British rock band', $info['biography']);
		$this->assertSame('https://www.theaudiodb.com/images/media/artist/thumb/test.jpg', $info['image_url']);
	}

	public function testForeignImageUrlIsRejected() : void {
		$info = ArtistMetadataService::extractAudioDbMetadata([
			'idArtist' => '111239', 'strArtistThumb' => 'https://untrusted.example/img.jpg',
		]);
		$this->assertNull($info['image_url']);
	}

	public function testWikimediaFallback() : void {
		$info = ArtistMetadataService::extractWikimediaMetadata(
			['annotation' => 'MusicBrainz notes'],
			['claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => 'Artist photo.jpg']]]]]],
			null
		);
		$this->assertSame('MusicBrainz notes', $info['biography']);
		$this->assertSame('https://commons.wikimedia.org/wiki/Special:FilePath/Artist%20photo.jpg?width=640', $info['image_url']);
	}

	public function testIncompleteRecordsRetrySoonerAndCanBeForced() : void {
		$time = 1800000000;
		$missing = ['biography' => null, 'image_url' => null, 'audiodb_id' => null, 'fetched_at' => $time];
		$this->assertFalse(ArtistMetadataService::shouldRefresh($missing, false, $time + 60));
		$this->assertTrue(ArtistMetadataService::shouldRefresh($missing, true, $time + 60));
		$this->assertTrue(ArtistMetadataService::shouldRefresh($missing, false, $time + 86400));
	}

	public function testCompleteRecordsAreNotRefetchedByRetryMissing() : void {
		$time = 1800000000;
		$complete = ['biography' => 'Bio', 'image_url' => 'https://theaudiodb.com/i.jpg', 'audiodb_id' => '123', 'fetched_at' => $time];
		$this->assertFalse(ArtistMetadataService::shouldRefresh($complete, true, $time + 86400));
		$this->assertTrue(ArtistMetadataService::shouldRefresh($complete, true, $time + 30 * 86400));
	}
}
