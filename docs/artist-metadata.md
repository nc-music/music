# MusicBrainz-matched artist metadata for OpenSubsonic

This optional patch for Nextcloud Music 3.3.0 retrieves missing artist photos and biographies. It does not modify music files or their tags. `MUSICBRAINZ_ARTISTID` is the authoritative identity. It does not accept artist-name-only matches, because names are ambiguous.

## Sources and matching

1. If a verified TheAudioDB numeric artist ID was cached, look up `artist.php?i=ID` and check that the returned `strMusicBrainzID` still matches.
2. Otherwise try `artist-mb.php?i=MBID` (direct MusicBrainz lookup).
3. If that fails and the local artist name exists, try `search.php?s=NAME`, accepting **only** a record whose `strMusicBrainzID` equals the requested MBID. Cache its `idArtist` for subsequent numeric lookups.
4. For missing fields, consult MusicBrainz relationships, Wikidata, Wikimedia Commons, and Wikipedia. Existing Last.fm biographies and manually configured artist artwork take precedence in the Subsonic response.

TheAudioDB's public V1 test key is `123` (default). Its pricing page currently lists MusicBrainz lookup and name search as premium features, even where test-key endpoints respond. The patch gracefully falls back if they are not available. To use your own key, add `'music.audiodb_api_key' => 'YOUR_KEY'` to Nextcloud's `config.php`. Set it to `''` to disable TheAudioDB.

Image URLs are returned to the client, rather than downloading or embedding images into your music library. Check provider license/attribution requirements before redistribution.

## Cache, retry, and rate limits

- New table `music_artist_info` stores biography, URL, MusicBrainz ID, last fetch, and the **verified TheAudioDB numeric ID**. One record can serve multiple Nextcloud users.
- A complete record is refreshed after 30 days. Any record missing a biography or artist image is retried automatically after 24 hours.
- `--retry-missing` retries incomplete records immediately without re-fetching fresh, complete entries. Failed network requests do not replace previously cached images or biographies.
- TheAudioDB requests are spaced by at least 3 seconds across workers (20 per minute, under the documented free tier of 30/min); MusicBrainz requests are spaced by at least 2 seconds. API requests skip occupied slots, but CLI backfill may wait for them.
- Artist listings read images from the database without making remote HTTP requests; the single-artist Subsonic endpoints may refresh metadata opportunistically.

## Deploying and backfilling

**Apply this patch to a clean `nc-music/music` 3.3.0 source checkout**, not on top of the earlier MusicBrainz-only patch. Back up the database and app directory first. This is a modified unsigned app, not an official release; Nextcloud integrity warnings are possible.

```sh
git apply --check /path/to/nextcloud-music-audiodb-musicbrainz-3.3.0.patch
git apply /path/to/nextcloud-music-audiodb-musicbrainz-3.3.0.patch
php occ upgrade
```

From the Nextcloud installation root, using the same PHP/container user normally used for `occ`:

```sh
php occ music:enrich-artists YOUR_USER --limit=20
php occ music:enrich-artists YOUR_USER
php occ music:enrich-artists YOUR_USER --retry-missing
```

After populating the cache, refresh the Nextcloud Music media provider in Symfonium. `getArtists`/`getArtist` expose `artistImageUrl`; `getArtistInfo`/`getArtistInfo2` include the biography and available image URL(s).

If the previous MusicBrainz-only patch was already deployed, this version's later migration adds the new `audiodb_id` field to the existing cache table. Remove the previous patch's code changes before applying this baseline patch; do not apply both patches directly. Test in staging first.
