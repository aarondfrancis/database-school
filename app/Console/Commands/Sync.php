<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace App\Console\Commands;

use Alaouy\Youtube\Facades\Youtube;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class Sync extends Command
{
    protected $signature = 'sync';

    protected $description = 'Sync YouTube videos to Transistor (as audio)';

    public function handle()
    {
        $transistor = Http::baseUrl('https://api.transistor.fm/v1/')
            ->withHeader('x-api-key', config('services.transistor'));

        $showId = 54476;

        $existing = $transistor
            ->get('episodes', [
                'show_id' => $showId
            ])
            ->collect('data')
            ->map(function ($episode) {
                parse_str(parse_url($episode['attributes']['video_url'])['query'], $queryParams);

                return $queryParams;
            })
            ->pluck('v')
            ->toArray();

        $this->info("There are " . count($existing) . " episodes on Transistor.");
        print_r($existing);

        $videos = Youtube::getPlaylistItemsByPlaylistId('PLI72dgeNJtzqElnNB6sQoAn2R-F3Vqm15');

        $remaining = collect($videos['results'])
            ->map(function ($video) {
                return [
                    'videoId' => $video->contentDetails->videoId,
                    'title' => $video->snippet->title,
                    'description' => $video->snippet->description,
                ];
            })
            ->reject(function ($video) use ($existing) {
                return in_array($video['videoId'], $existing);
            });

        if ($remaining->isEmpty()) {
            $this->info('There are no episodes that need to go on Transistor');
            return;
        }

        $this->info('There are episodes that need to go on Transistor');

        $remaining = $remaining->first();

//        $remaining = [
//            "id" => "UExJNzJkZ2VOSnR6cUVsbk5CNnNRb0FuMlItRjNWcW0xNS4yODlGNEE0NkRGMEEzMEQy",
//            "videoId" => "TmZrZFIumhM",
//            "title" => "Title!",
//            "description" => "Description!",
//            "created_at" => "2024-06-10T17:27:13Z"
//        ];
//        $path = storage_path('o81ffUBw.m4a');

        $filename = Str::random(8) . '.mp3';
        $path = storage_path($filename);

        $this->info("Filename: $filename");

        $process = Process::timeout(10 * 60)->start(
            "yt-dlp https://www.youtube.com/watch?v={$remaining['videoId']} -x --audio-format mp3 -o $path",
            function (string $type, string $output) {
                // echo $output;
            }
        );

        $process->wait()->throw();

        // Get the authorized upload URL for the media
        $authorization = $transistor
            ->get('episodes/authorize_upload', [
                'filename' => $filename
            ])
            ->throw()
            ->json('data');

        $this->info('Uploading...');

        // Upload the audio to Transistor's S3 bucket
        Http::attach($filename, fopen($path, 'r'), $filename)
            ->put($authorization['attributes']['upload_url'])
            ->throw();

        $this->info('Uploading! Deleting tmp file.');
        unlink($path);

        // Create the actual episode
        $episodeId = $transistor->post('episodes', [
            'episode' => [
                'show_id' => $showId,
                'title' => $remaining['title'],
                'increment_number' => true,
                'description' => $remaining['description'],
                'audio_url' => $authorization['attributes']['audio_url'],
                'video_url' => "https://www.youtube.com/watch?v={$remaining['videoId']}"
            ]
        ])
            ->throw()
            ->json('data.id');

        $this->line("Episode uploaded! ID: $episodeId");

        $transistor->patch("episodes/$episodeId/publish", [
            'episode' => [
                'status' => 'published'
            ]
        ])->throw();

        $this->line('Episode published!');
    }
}
