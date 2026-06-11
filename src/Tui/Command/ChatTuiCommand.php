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
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
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

        $tui = new Tui($this->buildStyleSheet());

        // Shared chat widgets, (re)used once an agent is chosen.
        $transcript = '';
        $markdown = new MarkdownWidget('');
        $prompt = new InputWidget();
        $prompt->setPrompt('You: ');

        $startChat = function (string $agentName) use ($tui, $markdown, $prompt, &$transcript): void {
            $agent = $this->agents->get($agentName);
            $messages = new MessageBag();

            $transcript = \sprintf("# Chat with **%s**\n\n_Ask anything. Streaming replies render as they arrive._\n\n", $agentName);
            $markdown->setText($transcript);

            // Bordered, expanding transcript pane with the input docked beneath it.
            $pane = new ContainerWidget();
            $pane->addStyleClass('transcript');
            $pane->expandVertically(true);
            $pane->add($markdown);

            $hint = new TextWidget('Enter: send   ·   exit / quit / Esc: leave');
            $hint->addStyleClass('hint');

            $tui->clear();
            $tui->add($pane)->add($prompt)->add($hint);
            $tui->setFocus($prompt);

            $prompt->onCancel(static fn () => $tui->stop());

            $prompt->onSubmit(function (SubmitEvent $event) use ($tui, $agent, $agentName, $prompt, $markdown, $messages, &$transcript): void {
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

                try {
                    $result = $agent->call($messages, ['stream' => true]);

                    if ($result instanceof StreamResult) {
                        $answer = '';
                        foreach ($result->getContent() as $delta) {
                            if ($delta instanceof TextDelta) {
                                $answer .= (string) $delta;
                                $this->flush($tui, $markdown, $transcript.$answer);
                            }
                        }
                    } elseif ($result instanceof TextResult) {
                        $answer = $result->getContent();
                    } else {
                        $answer = '_(unexpected response type from agent)_';
                    }

                    $messages->add(Message::ofAssistant($answer));
                    $transcript .= $answer."\n\n";
                } catch (\Throwable $e) {
                    $transcript .= \sprintf("\n> ⚠️ Error: %s\n\n", $e->getMessage());
                }

                $this->flush($tui, $markdown, $transcript);
            });
        };

        if (\is_string($agentArg) && '' !== $agentArg) {
            $startChat($agentArg);
        } else {
            $items = array_map(static fn (string $name): array => ['value' => $name, 'label' => $name], $agentNames);

            $picker = new ContainerWidget();
            $picker->addStyleClass('transcript');
            $picker->add(new TextWidget('Select an agent to chat with:'));

            $list = new SelectListWidget($items, min(10, \count($items)));
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
        $styles->addRule('.hint', new Style(color: 'gray', dim: true));

        return $styles;
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
