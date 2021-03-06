<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptAnswerRequest;
use App\Http\Requests\AddVoteRequest;
use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\UpdateAnswerRequest;
use App\Http\Requests\UpdatePostRequest;
use Session;
use App\Post;
use App\Tag;
use Illuminate\Http\Request;
use Auth;

class PostController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return mixed
     */
    public function index()
    {
        return view('posts.index')->with([
            'posts' => Post::with('author')->orderBy('created_at', 'DESC')->where('post_id', NULL)->paginate(10)
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return mixed
     */
    public function create()
    {
        $tags = Tag::all();
        return view('posts/create')->with(compact('tags'));
    }

    public function answer(Post $post)
    {
        return view('posts.create-answer', [
            'post' => $post->load('author', 'author.houseRole', 'author.houseRole.house'),
        ]);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param CreatePostRequest $request
     * @return mixed
     */
    public function store(CreatePostRequest $request)
    {
        try
        {
            \DB::beginTransaction();
            $post = Post::create([
                'title' => $request->get('title'),
                'content' => $request->get('content'),
                'post_id' => $request->has('question_id') ? $request->get('question_id') : null,
                'author_id' => \Auth::id(),
            ]);

            if ($request->has('tag'))
            {
                $post->tags()->attach($request->get('tag'));
            }

            // get points for an answer but not on your own question
            if ($post->isAnswer() )
            {
                // we don't want to assign points when answering your own question.
                if ($post->parent->author_id !== \Auth::user()->id )
                {
                    $type = \App\Point::BENEFACTOR_TYPE_QUESTION_ANSWERED;
                    \App\Point::assign(\Auth::user()->id, $type);
                }
            // get points for a question
            } else {
                $type = \App\Point::BENEFACTOR_TYPE_QUESTION_ASKED;
                \App\Point::assign(\Auth::user()->id, $type);
            }
            // assign the points
            \DB::commit();

        } catch (\Exception $e)
        {
            \DB::rollback();
            return redirect()->back()->with('error', 'error creating post.');
        }


        $redirect = $request->has('question_id') ? $request->get('question_id') : $post->id;

        return redirect()->action('PostController@show', $redirect)->with('Success','Post succesfully created.');
    }

    /**
     * Display the specified resource.
     *
     * @param  Post $post
     * @return mixed
     */
    public function show(Post $post)
    {
        //handle views per post
        if (! Session::has('viewed')) {
            Session::put('viewed', []);
        }

        if (! in_array($post->id, Session::get('viewed'))) {
            Session::push('viewed', $post->id);
            Post::find($post->id)->increment('views');
        }

    //        $post = Post::with([
    //            'comments', 'comments.author', 'author', 'author.houseRole', 'author.houseRole.house', 'votes'
    //        ])->get();
    //        dd($post);

        return view('posts.show', [
            'post' => $post,
            'replies' => Post::with('votes')->where('post_id', $post->id)
                ->orderBy('accepted_answer', 'DESC')
                ->orderBy('votes', 'DESC')
                ->orderBy('created_at', 'DESC')
                ->get(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Post $post
     * @return mixed
     */
    public function edit(Post $post)
    {
        return view('posts/edit', [
            'post' => $post->load('author', 'author.houseRole', 'author.houseRole.house', 'votes'),
            'tags' => Tag::all()
        ]);
    }

    /**
     *
     * Show form for editing answer
     *
     * @param  Post $post
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     *
     */
    public function editAnswer(Post $post)
    {
        $post =  $post->load('author', 'author.houseRole', 'author.houseRole.house', 'votes');
        $tags =  Tag::all();
        return view('posts/edit-answer', compact('post', 'tags'));

    }


    /**
     * Update the specified resource in storage.
     *

     * @param UpdatePostRequest $request
     * @param  int $id
     * @return mixed
     */
    public function update(UpdatePostRequest $request, $id)
    {
        $post = Post::findOrFail($id);

        try
        {
            \DB::beginTransaction();
            $post->update($request->all());
            if($request->has('tag'))
            {
                $post->tags()->detach();
                $post->tags()->attach($request->get('tag'));
            }
            \DB::commit();
        } catch(\Exception $e)
        {
            \DB::rollback();
            return redirect()->back()->with('error', 'Error editing post.: <br>' . $e->getMessage());
        }


        return redirect()->action('PostController@show', $post)->with('success', 'Succesfully edited your question.');

    }


    public function updateAnswer(UpdateAnswerRequest $request, $id)
    {
        $post = Post::findOrFail($id);
        $post->update($request->all());
        return redirect()->action('PostController@show', $post->parent->id)->with('success', 'Succesfully edited your answer.');
    }

    /**
     * Accepts this as answer to question

     * @param AcceptAnswerRequest $request
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function accept(AcceptAnswerRequest $request, $id)
    {
        $accepted = $request->get('accepted');

        $post = Post::find($id);
        $post->update([
            'accepted_answer' => $accepted,
        ]);

        if( $accepted ){
            // accepted answer author gets points.
            if ( \Auth::user()->id !== $post->parent->author_id)
            {
                \App\Point::assign($post->author_id, \App\Point::BENEFACTOR_TYPE_ANSWER_ACCEPTED);
            }
            $message = 'Answer has been accepted.';
        }else{

            if ( \Auth::user()->id !== $post->parent->author_id)
            {
                \App\Point::deAssign($post->author_id, \App\Point::BENEFACTOR_TYPE_ANSWER_ACCEPTED);
            }
            $message = 'Accepted answer is undone.';
        }

        return redirect()->back()->with('success', $message);
    }

    public function vote(AddVoteRequest $request,Post $post)
    {
        $data = [
            'user_id' => \Auth::user()->id,
            'post_id' => $post->id
        ];

             $data['vote'] = 1;
            $post->increment('votes');

        $post->votes()->create([
            'user_id' => auth()->id(),
            'vote' => 1,
        ]);

        return redirect()->back();
    }

    public function flag(Request $request, Post $post)
    {
        $post->increment('flags');
        $user = Auth::user();
        $user->flags()->attach($post->id);
        return back();

    }


    public function filter(Request $request)
    {
        if (empty($request->tags))
        {
            return redirect()->action('HomeController@index');
        }
        $posts = Post::with('author')->
                whereHas('tags', function($query) use ($request){
                    $query->whereIn('tags.id', $request->tags);
                })->
                orderBy('created_at', 'DESC')->
                where('post_id', NULL)->
                paginate(10);

        return view('home', [
            'posts' => $posts,
            'searchTags' => $request->tags
        ]);
    }

    public function search(Request $request)
    {
        if (empty($request->get('query')))
        {
            return redirect()->action('HomeController@index');
        }
        $query = $request->get('query');
        $posts = Post::with('author')->
        orderBy('created_at', 'DESC')->
        where('post_id', NULL)->
        where('content', 'like', "%$query%")->
        orWhere('title', 'like', "%$query%")->
        paginate(10);


        return view('home', [
            'posts' => $posts,
            'query' => $request->get('query')
        ]);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Post $post
     * @return mixed
     */
    public function destroy(Post $post)
    {

        // check whether post was an answer for determining the point type.
        $type = $post->isAnswer() ? \App\Point::BENEFACTOR_TYPE_QUESTION_ANSWERED : \App\Point::BENEFACTOR_TYPE_QUESTION_ASKED;

        \App\Point::deAssign($post->author_id, $type);
        $post->children()->delete();
        $post->delete();

    }
}
