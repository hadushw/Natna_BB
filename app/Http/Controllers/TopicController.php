<?php
/**
 * Topic Controller.
 *
 * Used to view, create, delete and update topics.
 *
 * @version 2.0.0
 * @author  MyBB Group
 * @license LGPL v3
 */

namespace MyBB\Core\Http\Controllers;

use Illuminate\Auth\Guard;
use MyBB\Core\Database\Models\Topic;
use MyBB\Core\Database\Repositories\IForumRepository;
use MyBB\Core\Database\Repositories\IPostRepository;
use MyBB\Core\Database\Repositories\ITopicRepository;
use MyBB\Core\Http\Requests\Topic\CreateRequest;
use MyBB\Core\Http\Requests\Topic\ReplyRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TopicController extends Controller
{
	/** @var ITopicRepository $topicRepository */
	private $topicRepository;
	/** @var IPostRepository $postRepository */
	private $postRepository;
	/** @var IForumRepository $forumRepository */
	private $forumRepository;
	/** @var Guard $guard */
	private $guard;

	/**
	 * @param ITopicRepository $topicRepository Topic repository instance, used to fetch topic details.
	 * @param IPostRepository  $postRepository  Post repository instance, used to fetch post details.
	 * @param IForumRepository $forumRepository Forum repository interface, used to fetch forum details.
	 * @param Guard            $guard           Guard implementation
	 */
	public function __construct(
		ITopicRepository $topicRepository,
		IPostRepository $postRepository,
		IForumRepository $forumRepository,
		Guard $guard
	) {
		$this->topicRepository = $topicRepository;
		$this->postRepository = $postRepository;
		$this->forumRepository = $forumRepository;
		$this->guard = $guard;
	}

	public function show($slug = '')
	{
		$topic = $this->topicRepository->findBySlug($slug);

		if (!$topic) {
			throw new NotFoundHttpException(trans('errors.topic_not_found'));
		}

		$this->topicRepository->incrementViewCount($topic);

		$posts = $this->postRepository->allForTopic($topic, true);

		return view('topic.show', compact('topic', 'posts'));
	}

	public function last($slug = '')
	{
		$topic = $this->topicRepository->findBySlug($slug);

		if (!$topic) {
			throw new NotFoundHttpException(trans('errors.topic_not_found'));
		}

		if ($this->guard->user() == null) {
			// Todo: default to board setting
			$ppp = 10;
		} else {
			$ppp = $this->guard->user()->settings->posts_per_page;
		}
		if (ceil($topic->num_posts / $ppp) == 1) {
			return redirect()->route('topics.show', ['slug' => $topic->slug, '#post-' . $topic->last_post_id]);
		} else {
			return redirect()->route('topics.show', [
				'slug' => $topic->slug,
				'page' => ceil($topic->num_posts / $ppp),
				'#post-' . $post->id
			]);
		}
	}

	public function reply($slug = '')
	{
		$topic = $this->topicRepository->findBySlug($slug);

		if (!$topic) {
			throw new NotFoundHttpException(trans('errors.topic_not_found'));
		}

		return view('topic.reply', compact('topic'));
	}

	public function postReply($slug = '', ReplyRequest $replyRequest)
	{
		/** @var Topic $topic */
		$topic = $this->topicRepository->findBySlug($slug);

		if (!$topic) {
			throw new NotFoundHttpException(trans('errors.topic_not_found'));
		}

		$post = $this->postRepository->addPostToTopic($topic, [
			'content' => $replyRequest->input('content'),
		]);

		if ($post) {
			if ($this->guard->user() == null) {
				// Todo: default to board setting
				$ppp = 10;
			} else {
				$ppp = $this->guard->user()->settings->posts_per_page;
			}
			if (ceil($topic->num_posts / $ppp) == 1) {
				return redirect()->route('topics.show', ['slug' => $topic->slug, '#post-' . $post->id]);
			} else {
				return redirect()->route('topics.show', [
					'slug' => $topic->slug,
					'page' => ceil($topic->num_posts / $ppp),
					'#post-' . $post->id
				]);
			}
		}

		return new \Exception(trans('errors.error_creating_post')); // TODO: Redirect back with error...
	}

	public function edit($slug = '', $id = 0)
	{
		$topic = $this->topicRepository->findBySlug($slug);
		$post = $this->postRepository->find($id);

		if (!$post || !$topic || $post['topic_id'] != $topic['id']) {
			throw new NotFoundHttpException(trans('errors.post_not_found'));
		}

		return view('topic.edit', compact('post', 'topic'));
	}

	public function postEdit($slug = '', $id = 0, ReplyRequest $replyRequest)
	{
		$topic = $this->topicRepository->findBySlug($slug);
		$post = $this->postRepository->find($id);

		if (!$post || !$topic || $post['topic_id'] != $topic['id']) {
			throw new NotFoundHttpException(trans('errors.post_not_found'));
		}

		$post = $this->postRepository->editPost($post, [
			'content' => $replyRequest->input('content'),
		]);
		if ($post['id'] == $topic['first_post_id']) {
			$topic = $this->topicRepository->editTopic($topic, [
				'title' => $replyRequest->input('title'),
			]);
		}

		if ($post) {
			return redirect()->route('topics.show', ['slug' => $topic->slug]);
		}

		return new \Exception('Error editing post'); // TODO: Redirect back with error...
	}

	public function create($forumId)
	{
		$forum = $this->forumRepository->find($forumId);

		if (!$forum) {
			throw new NotFoundHttpException(trans('errors.forum_not_found'));
		}

		return view('topic.create', compact('forum'));
	}

	public function postCreate($forumId = 0, CreateRequest $createRequest)
	{
		$topic = $this->topicRepository->create([
			                                        'title' => $createRequest->input('title'),
			                                        'forum_id' => $createRequest->input('forum_id'),
			                                        'first_post_id' => 0,
			                                        'last_post_id' => 0,
			                                        'views' => 0,
			                                        'num_posts' => 0,
			                                        'content' => $createRequest->input('content'),
		                                        ]);

		if ($topic) {
			return redirect()->route('topics.show', ['slug' => $topic->slug]);
		}

		return new \Exception(trans('errors.error_creating_topic')); // TODO: Redirect back with error...
	}


	public function restore($slug = '', $id = 0)
	{
		$topic = $this->topicRepository->findBySlug($slug);
		$post = $this->postRepository->find($id);

		if (!$post || !$topic || $post['topic_id'] != $topic['id'] || !$post['deleted_at']) {
			throw new NotFoundHttpException(trans('errors.post_not_found'));
		}

		/*if ($post['id'] == $topic['first_post_id']) {
			$forum = $this->forumRepository->find($topic['forum_id']);

			$this->topicRepository->deleteTopic($topic);

			return redirect()->route('forums.show', ['slug' => $topic->forum['slug']]);
		} else {*/// I'll work on it later.
			$this->postRepository->restorePost($post);
			$posts = $this->postRepository->allForTopic($topic);
			$topic = $this->topicRepository->editTopic($topic, [
				'last_post_id' => $posts[count($posts) - 1]['id']
			]);
			return redirect()->route('topics.show', ['slug' => $topic['slug']]);
		//}

		return new \Exception(trans('errors.error_deleting_topic')); // TODO: Redirect back with error...
	}



	public function delete($slug = '', $id = 0)
	{
		$topic = $this->topicRepository->findBySlug($slug);
		$post = $this->postRepository->find($id);

		if (!$post || !$topic || $post['topic_id'] != $topic['id']) {
			throw new NotFoundHttpException(trans('errors.post_not_found'));
		}


		if ($post['id'] == $topic['first_post_id']) {
			$forum = $this->forumRepository->find($topic['forum_id']);

			$this->topicRepository->deleteTopic($topic);

			return redirect()->route('forums.show', ['slug' => $topic->forum['slug']]);
		} else {
			if ($post['id'] == $topic['last_post_id'] && $post['deleted_at'] == null) {
				$posts = $this->postRepository->allForTopic($topic);
				$topic = $this->topicRepository->editTopic($topic, [
					'last_post_id' => $posts[count($posts) - 2]['id']
				]);
			}
			$this->postRepository->deletePost($post);

			return redirect()->route('topics.show', ['slug' => $topic['slug']]);
		}

		return new \Exception(trans('errors.error_deleting_topic')); // TODO: Redirect back with error...
	}
}
