<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Regression for issue #25 — Mailer::sandboxedTwig() must allow `extends`,
 * `block` a `use` tags. DB-uložené šablony dědí z `_layout.html.twig`
 * (viz `EmailTemplateAction::loadDefaults` který vrací celé tělo včetně
 * `{% extends %}{% block content %}`). Před fixem sandbox házel
 * `Tag "block" is not allowed in "_layout.html.twig" at line 63.` po každé
 * editaci šablony v adminu.
 *
 * Test nepotřebuje DB ani SMTP — invokuje privátní `sandboxedTwig()` přes
 * reflexi a renderuje minimální string template, která dědí ze skutečného
 * `_layout.html.twig` z `api/templates/email/`.
 */
final class MailerSandboxRenderTest extends TestCase
{
    private \Twig\Environment $sandbox;

    protected function setUp(): void
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Mailer::class, 'sandboxedTwig');
        $this->sandbox = $method->invoke($mailer);
    }

    public function testDbTemplateExtendsLayout(): void
    {
        // Tohle je přesně to, co user uloží přes admin UI po jakékoli úpravě:
        // celé tělo včetně extends/block dědící z _layout.html.twig.
        $body = "{% extends '_layout.html.twig' %}\n"
              . "{% block content %}\n"
              . "<p>Faktura {{ invoice.varsymbol }}</p>\n"
              . "{% endblock %}\n";

        $html = $this->sandbox->createTemplate($body)->render([
            'locale'   => 'cs',
            'subject'  => 'Test',
            'supplier' => null,
            'invoice'  => ['varsymbol' => '2605001'],
        ]);

        self::assertStringContainsString('Faktura 2605001', $html);
        self::assertStringContainsString('<!doctype html>', $html);
    }

    public function testDbTextTemplateExtendsLayout(): void
    {
        $body = "{% extends '_layout.txt.twig' %}\n"
              . "{% block content %}Varsymbol: {{ invoice.varsymbol }}{% endblock %}\n";

        $text = $this->sandbox->createTemplate($body)->render([
            'locale'   => 'cs',
            'supplier' => null,
            'invoice'  => ['varsymbol' => '2605001'],
        ]);

        self::assertStringContainsString('Varsymbol: 2605001', $text);
    }

    public function testSandboxStillBlocksDangerousTags(): void
    {
        // Defense-in-depth — fix #25 neuvolnil sandbox víc, než je třeba.
        // `include` zůstává zakázaný (mohl by načíst arbitrary template, byť
        // omezený FilesystemLoader rootem na api/templates/email/).
        $this->expectException(\Twig\Sandbox\SecurityNotAllowedTagError::class);
        $this->sandbox->createTemplate("{% include '_layout.html.twig' %}")->render([]);
    }
}
