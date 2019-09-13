<?php

namespace App\Http\Controllers\Api\v1\Author;

use App\Jobs\UploadVideo;
use Illuminate\Http\Request;
use App\Repositories\Contracts\ILesson;
use App\Repositories\Contracts\IContent;
use App\Http\Resources\ContentResource;
use App\Http\Controllers\Controller;
//use App\Jobs\ConvertVideoForStreaming;


class AuthorContentController extends Controller
{
    
    protected $lessons;
    protected $contents;
    
    public function __construct(ILesson $lessons, IContent $contents)
    {
        $this->lessons = $lessons;
        $this->contents = $contents;
    }
    
    public function findByLesson($id)
    {
        $content = $this->contents->findByLesson($id);
        return new ContentResource($content);
    }
    
    public function uploadVideo(Request $request, $id)
    {
        $lesson = $this->lessons->find($id);
        $originalFileName = $request->file('file')->getClientOriginalName();
        $ext = $request->file('file')->extension();
        $getID3 = new \getID3;
        $file = $getID3->analyze($request->file('file'));
        $duration_in_seconds = $file['playtime_seconds'];
        if($lesson->video->count() && $lesson->content_type=='video'){
            $currentVideoSm = $lesson->video->streamable_sm;
            $currentVideoLg = $lesson->video->streamable_lg;
            $this->contents->destroyVideo($lesson->video->id);
            $this->contents->deleteVideo($currentVideoSm);
            $this->contents->deleteVideo($currentVideoLg);
        }
        $file_base = time() . '-' . substr(\Str::slug($originalFileName), 0, -3);
        $filename = $file_base .'.'.$ext;
        $path = $request->file('file')->storeAs('uploads', $filename, 'tmpStorage');
        
        // $content = $this->contents->createVideoContent([
        //     'content_type' => 'video',
        //     'video_filename' => $filename,
        //     'video_duration' => $duration_in_seconds/60, // duration in seconds
        //     'video_path' => config('site_settings.video_upload_location') == 's3' ? \Storage::disk('s3')->url($filename) : '/uploads/videos/'.$filename,
        //     'video_src' => 'upload',
        //     'video_storage' => config('site_settings.video_upload_location')   
        // ], $id);
        
        $video = $this->contents->createVideo([
            'encoded' => false,
            'streamable_sm' => null,
            'streamable_lg' => null,
            'converted_for_streaming_at' => null,
            'original_filename' => $filename,
            'is_processed' => false,
            'duration' => $duration_in_seconds/60
        ], $id);

        // if job fails, remove the content
        if(setting('site.encode_videos')){
            ConvertVideoForStreaming::dispatch($video);
        } else {
            UploadVideo::dispatch($video);
        }
        
        return response()->json(null, 200);
        
    }
    
    public function store(Request $request)
    {
        if($request->type=='youtube'){
            $this->validate($request, [
                'url' => 'required|url|youtube',
                'duration' => 'required|numeric|min:1'
            ]);
            
            return $this->contents->createYoutube($request->all());
        }
        
        // store article
        $this->validate($request, [
            'content' => 'required|string',
        ]);
        
        return $this->contents->updateArticle($request->all());
        
    }

}