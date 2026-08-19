<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\UrlGenerator;
use App\Core\Session\Session;
use App\Core\View\ViewRenderer;
use App\Models\ContactMessage;
use App\Repositories\ContactMessageRepository;
use App\Services\AuthService;

/** Messaggi ricevuti dal modulo contatti. */
final class MessageController extends Controller
{
    public function __construct(
        ViewRenderer $view,
        Session $session,
        UrlGenerator $url,
        AuthService $auth,
        Config $config,
        private readonly ContactMessageRepository $messages,
    ) {
        parent::__construct($view, $session, $url, $auth, $config);
    }

    public function index(Request $request): Response
    {
        $this->authorize('contacts.manage');

        $status = $request->string('stato');

        return $this->render('admin/messages/index.twig', [
            'seo' => $this->seo('Messaggi')->withNoindex(),
            'paginator' => $this->messages->paginate(
                page: $this->page($request),
                perPage: 20,
                status: $status !== '' ? $status : null,
                basePath: $this->url->route('admin.messages.index'),
            ),
            'activeStatus' => $status,
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function show(Request $request): Response
    {
        $this->authorize('contacts.manage');

        $message = $this->messages->find($request->routeInt('id'));

        if ($message === null) {
            $this->notFound('Messaggio non trovato.');
        }

        // Aprire il messaggio equivale a leggerlo: nessun pulsante aggiuntivo.
        $this->messages->markRead($message->id, $this->currentUser()->id);

        return $this->render('admin/messages/show.twig', [
            'seo' => $this->seo('Messaggio')->withNoindex(),
            'message' => $message,
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        $this->authorize('contacts.manage');

        $id = $request->routeInt('id');
        $status = $request->string('status');

        if (! array_key_exists($status, $this->statusOptions())) {
            $this->error('Stato non valido.');

            return $this->redirectToRoute('admin.messages.show', ['id' => $id]);
        }

        $this->messages->updateStatus($id, $status);
        $this->success('Stato aggiornato.');

        return $this->redirectToRoute('admin.messages.index');
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('contacts.manage');

        $this->messages->delete($request->routeInt('id'));
        $this->success('Messaggio eliminato.');

        return $this->redirectToRoute('admin.messages.index');
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            ContactMessage::STATUS_NEW => 'Nuovo',
            ContactMessage::STATUS_READ => 'Letto',
            ContactMessage::STATUS_REPLIED => 'Risposto',
            ContactMessage::STATUS_ARCHIVED => 'Archiviato',
            ContactMessage::STATUS_SPAM => 'Spam',
        ];
    }
}
