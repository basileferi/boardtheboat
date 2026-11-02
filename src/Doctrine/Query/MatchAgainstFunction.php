<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MySQL/MariaDB full-text predicate, exposed to DQL as:
 *
 *     MATCH_AGAINST(o.title, o.description, :query)
 *     MATCH_AGAINST(o.title, o.description, :query, 'boolean')
 *
 * The listed fields must be covered by one FULLTEXT index (see JobOffer). The search terms
 * always travel as a bound parameter, never as concatenated SQL.
 *
 * Registered in config/packages/doctrine.yaml as both a string and a numeric function so it
 * can be used in a WHERE clause and in a SELECT/ORDER BY relevance expression.
 */
final class MatchAgainstFunction extends FunctionNode
{
    /** @var list<PathExpression> */
    private array $fields = [];

    private Node $against;

    private bool $booleanMode = false;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->fields[] = $parser->StateFieldPathExpression();

        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);

            if ($this->nextTokenIsField($parser)) {
                $this->fields[] = $parser->StateFieldPathExpression();

                continue;
            }

            break;
        }

        $this->against = $parser->StringPrimary();

        if ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $mode = $parser->StringPrimary();
            $this->booleanMode = str_contains(mb_strtolower($this->literalValue($mode)), 'boolean');
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $columns = array_map(
            static fn (PathExpression $field): string => $sqlWalker->walkPathExpression($field),
            $this->fields,
        );

        return \sprintf(
            'MATCH (%s) AGAINST (%s%s)',
            implode(', ', $columns),
            $sqlWalker->walkStringPrimary($this->against),
            $this->booleanMode ? ' IN BOOLEAN MODE' : '',
        );
    }

    /**
     * A field looks like "alias.property": an identifier immediately followed by a dot.
     * Anything else (a parameter, a literal) is the search term and ends the field list.
     */
    private function nextTokenIsField(Parser $parser): bool
    {
        $lexer = $parser->getLexer();

        if (!$lexer->isNextToken(TokenType::T_IDENTIFIER)) {
            return false;
        }

        $glimpse = $lexer->glimpse();

        return null !== $glimpse && TokenType::T_DOT === $glimpse->type;
    }

    private function literalValue(Node $node): string
    {
        return property_exists($node, 'value') && \is_string($node->value) ? $node->value : '';
    }
}
