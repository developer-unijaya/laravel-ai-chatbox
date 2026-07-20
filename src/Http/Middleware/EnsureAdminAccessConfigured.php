<?php
namespace DeveloperUnijaya\AiChatbox\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed tripwire for the admin dashboard and Knowledge Base routes.
 *
 * The package ships with a bare [web, auth] gate so a fresh install works with
 * zero configuration for local testing. That gate lets ANY authenticated user
 * reach the dashboard (every user's transcripts, provider config, token tails)
 * and the RAG endpoints (upload/delete/reprocess the knowledge base). Outside
 * local/testing that is unsafe, so this middleware is appended by the service
 * provider only when the resolved gate is still the bare default AND the app is
 * not in a local/testing environment — in which case it refuses the request
 * with a 403 explaining exactly what to configure. Once the integrator sets a
 * real gate on admin_middleware / rag_admin_middleware this middleware is no
 * longer added, so it never runs on a properly-configured install.
 */
class EnsureAdminAccessConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(403,
            'AI Chatbox admin/Knowledge Base access is not configured. These routes are '
            . 'only protected by the default [web, auth] middleware — which allows any '
            . 'authenticated user in — so they are refused outside local/testing. Set '
            . '"admin_middleware" and "rag_admin_middleware" in config/ai-chatbox.php to a '
            . 'real gate, e.g. "role:admin" (Spatie), "can:manage-ai-chatbox" (Laravel '
            . 'Gate), or your own middleware.'
        );
    }
}
