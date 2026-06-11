<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tui\Command;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Chat with any configured agent in a rich terminal UI, built on the
 * experimental Symfony TUI component. The plain-text counterpart is the
 * bundle's `ai:chat` (ai:agent:call) command.
 *
 * Run with `-v` (or higher) to open a debug pane that logs each turn's tool
 * calls and token usage as the agent runs.
 *
 * Widget/layout patterns here mirror the symfony/tui example project at
 * https://github.com/mattleads/TuiComponent (bordered panes via a StyleSheet,
 * an expanding content pane with the input docked beneath it, and a hint line).
 */
#[AsCommand(
    name: 'app:tui',
    description: 'Chat with an agent in a rich terminal UI (Symfony TUI component).',
)]
final class ChatTuiCommand extends Command
{
    /**
     * @param ServiceProviderInterface<AgentInterface> $agents
     */
    public function __construct(
        #[AutowireLocator('ai.agent', indexAttribute: 'name')]
        private readonly ServiceProviderInterface $agents,
    ) {
        parent::__construct();
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('agent')) {
            $suggestions->suggestValues(array_keys($this->agents->getProvidedServices()));
        }
    }

    protected function configure(): void
    {
        $this->addArgument('agent', InputArgument::OPTIONAL, 'The name of the agent to chat with. If omitted, you can pick one in the UI.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agentNames = array_keys($this->agents->getProvidedServices());

        if (0 === \count($agentNames)) {
            $io->error('No agents are configured.');

            return Command::FAILURE;
        }

        $agentArg = $input->getArgument('agent');
        if (\is_string($agentArg) && '' !== $agentArg && !$this->agents->has($agentArg)) {
            $io->error(\sprintf('Agent "%s" not found. Available agents: "%s"', $agentArg, implode(', ', $agentNames)));

            return Command::FAILURE;
        }

        // -v (or higher) opens a debug pane that logs tool calls and token usage.
        $debug = $output->isVerbose();
        $tui = new Tui($this->buildStyleSheet());

        // Shared chat widgets, (re)used once an agent is chosen.
        $transcript = '';
        $markdown = new MarkdownWidget('');
        $prompt = new InputWidget();
        $prompt->setPrompt('You: ');

        // Debug pane state (only mounted when $debug is true).
        $debugWidget = new TextWidget('Waiting for the first message…');
        $debugLines = [];
        $logDebug = function (string $line) use ($tui, $debugWidget, &$debugLines): void {
            $debugLines[] = $line;
            $debugLines = \array_slice($debugLines, -14);
            $debugWidget->setText(implode("\n", $debugLines));
            $tui->requestRender();
            $tui->processRender();
        };

        $startChat = function (string $agentName) use ($tui, $markdown, $prompt, $debug, $debugWidget, $logDebug, &$transcript): void {
            $agent = $this->agents->get($agentName);
            $messages = new MessageBag();

            $transcript = \sprintf("# Chat with **%s**\n\n_Ask anything. Streaming replies render as they arrive._\n\n", $agentName);
            $markdown->setText($transcript);

            // Bordered, expanding transcript pane with the input docked beneath it.
            $pane = new ContainerWidget();
            $pane->addStyleClass('transcript');
            $pane->expandVertically(true);
            $pane->add($markdown);

            $hint = new TextWidget(
                $debug
                    ? 'Enter: send   ·   exit / quit / Esc: leave   ·   debug pane: ON (-v)'
                    : 'Enter: send   ·   exit / quit / Esc: leave   ·   re-run with -v for a debug pane'
            );
            $hint->addStyleClass('hint');

            $tui->clear();
            $tui->add($pane);

            if ($debug) {
                $debugPane = new ContainerWidget();
                $debugPane->addStyleClass('debug');
                $debugPane->add(new TextWidget('agent debug — tool calls & token usage'));
                $debugPane->add($debugWidget);
                $tui->add($debugPane);
            }

            $tui->add($prompt)->add($hint);
            $tui->setFocus($prompt);

            $prompt->onCancel(static fn () => $tui->stop());

            $prompt->onSubmit(function (SubmitEvent $event) use ($tui, $agent, $agentName, $prompt, $markdown, $messages, $debug, $logDebug, &$transcript): void {
                $question = trim($event->getValue());
                $prompt->setValue('');

                if ('' === $question) {
                    return;
                }

                if (\in_array(strtolower($question), ['exit', 'quit'], true)) {
                    $tui->stop();

                    return;
                }

                $messages->add(Message::ofUser($question));
                $transcript .= \sprintf("**You:** %s\n\n**%s:** ", $question, $agentName);
                $this->flush($tui, $markdown, $transcript);

                if ($debug) {
                    $logDebug(\sprintf('▶ %s ← %s', $agentName, $this->trim($question)));
                }

                try {
                    $result = $agent->call($messages, ['stream' => true]);

                    if ($result instanceof StreamResult) {
                        $answer = '';
                        $thinkingLogged = false;
                        foreach ($result->getContent() as $delta) {
                            if ($delta instanceof TextDelta) {
                                $answer .= (string) $delta;
                                $this->flush($tui, $markdown, $transcript.$answer);
                            } elseif ($debug && $delta instanceof ToolCallStart) {
                                $logDebug('  🔧 tool → '.$delta->getName());
                            } elseif ($debug && !$thinkingLogged && $delta instanceof ThinkingStart) {
                                $thinkingLogged = true;
                                $logDebug('  💭 thinking…');
                            }
                        }
                    } elseif ($result instanceof TextResult) {
                        $answer = $result->getContent();
                    } else {
                        $answer = '_(unexpected response type from agent)_';
                    }

                    $messages->add(Message::ofAssistant($answer));
                    $transcript .= $answer."\n\n";

                    if ($debug && null !== ($usage = $this->tokenUsageLine($result))) {
                        $logDebug($usage);
                    }
                } catch (\Throwable $e) {
                    $transcript .= \sprintf("\n> ⚠️ Error: %s\n\n", $e->getMessage());
                    if ($debug) {
                        $logDebug('  ⚠️ '.$e->getMessage());
                    }
                }

                $this->flush($tui, $markdown, $transcript);
            });
        };

        if (\is_string($agentArg) && '' !== $agentArg) {
            $startChat($agentArg);
        } else {
            $descriptions = $this->agentDescriptions();
            $items = array_map(
                static fn (string $name): array => [
                    'value' => $name,
                    'label' => $name,
                    'description' => $descriptions[$name] ?? 'agent',
                ],
                $agentNames,
            );

            $picker = new ContainerWidget();
            $picker->addStyleClass('transcript');
            $picker->add(new TextWidget('Select an agent to chat with:'));

            $list = new SelectListWidget($items, \count($items));
            $picker->add($list);

            $hint = new TextWidget('↑/↓ + Enter: choose   ·   Esc: cancel');
            $hint->addStyleClass('hint');

            $tui->add($picker)->add($hint);
            $tui->setFocus($list);

            $list->onSelect(static fn (SelectEvent $event) => $startChat($event->getValue()));
            $list->onCancel(static fn () => $tui->stop());
        }

        $tui->run();

        return Command::SUCCESS;
    }

    private function buildStyleSheet(): StyleSheet
    {
        $styles = new StyleSheet();
        $styles->addRule('.transcript', new Style(padding: Padding::all(1), border: Border::all(1, 'rounded', 'cyan')));
        $styles->addRule('.debug', new Style(padding: Padding::all(1), border: Border::all(1, 'rounded', 'gray')));
        $styles->addRule('.hint', new Style(color: 'gray', dim: true));

        return $styles;
    }

    /**
     * Short, human-readable blurbs for the agent picker — purpose + backend, so
     * the list distinguishes task agents (blog/recipe/…) from the plain
     * model-backend agents (chatgpt/cerebras) and the multi-agent plumbing.
     * Mirrors config/packages/ai.yaml.
     *
     * @return array<string, string>
     */
    private function agentDescriptions(): array
    {
        return [
            'blog' => 'RAG over the Symfony blog · OpenAI gpt-4.1',
            'stream' => 'Streaming chat demo · OpenAI gpt-4.1',
            'youtube' => 'YouTube transcript Q&A · OpenAI gpt-5-mini',
            'recipe' => 'Cooking assistant · Cerebras gpt-oss-120b',
            'wikipedia' => 'Wikipedia-grounded answers · OpenAI gpt-5-mini',
            'speech' => 'Voice assistant (delegates to blog) · OpenAI gpt-5-mini',
            'cerebras' => 'Plain chat · Cerebras gpt-oss-120b (free tier ~5 req/min)',
            'chatgpt' => 'Plain chat · OpenAI gpt-4.1',
            'orchestrator' => "Router for the 'support' multi-agent · OpenAI gpt-5-mini",
            'technical' => "Tech-support handoff in 'support' · OpenAI gpt-5-mini",
            'fallback' => "General fallback in 'support' · OpenAI gpt-5-mini",
            'support' => 'Multi-agent: routes to technical/fallback',
        ];
    }

    /**
     * Format the token usage attached to a result's metadata (set by the
     * platform during/after the call), or null if none is available.
     */
    private function tokenUsageLine(object $result): ?string
    {
        if (!method_exists($result, 'getMetadata')) {
            return null;
        }

        $usage = $result->getMetadata()->get('token_usage');
        if (!$usage instanceof TokenUsage) {
            return null;
        }

        $parts = [];
        if (null !== $usage->getPromptTokens()) {
            $parts[] = 'in '.$usage->getPromptTokens();
        }
        if (null !== $usage->getCompletionTokens()) {
            $parts[] = 'out '.$usage->getCompletionTokens();
        }
        if (null !== $usage->getTotalTokens()) {
            $parts[] = 'total '.$usage->getTotalTokens();
        }
        if (null !== $usage->getRemainingTokensMinute()) {
            $parts[] = 'rem/min '.$usage->getRemainingTokensMinute();
        }

        return $parts ? '  📊 tokens: '.implode('  ', $parts) : null;
    }

    private function trim(string $text, int $max = 48): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * Push the latest transcript into the markdown pane and flush it to the
     * terminal immediately, so streamed deltas appear as they arrive even
     * while the submit handler is blocking the event loop.
     */
    private function flush(Tui $tui, MarkdownWidget $markdown, string $text): void
    {
        $markdown->setText($text);
        $tui->requestRender();
        $tui->processRender();
    }
}
