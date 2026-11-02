<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use App\Enum\ContractType;

/**
 * Decides whether an offer belongs in a dev/tech internship feed, and which contract it is.
 *
 * Providers tag loosely ("Intern" covers accounting and journalism too), so the title and the
 * provider's own tags are read against explicit vocabularies. A word list anyone can read beats
 * a clever classifier nobody can debug.
 */
final class TechFilter
{
    private const string TECH = '/\b(d[eé]velopp?(eur|euse|er|ement)|developer|software|logiciels?|programm(er|eur|ing|ation)|full[- ]?stack|front[- ]?end|back[- ]?end|web|mobile|android|ios|devops|sre|cloud|syst[eè]mes? d.information|systemintegration|r[eé]seaux?|cyber\w*|security engineer|data|machine learning|ml|ai|ia|intelligence artificielle|qa|test(eur|er|ing)|automation|informatique|it|php|symfony|python|java|javascript|typescript|react|angular|vue|node|golang|rust|kotlin|swift|sql|ux|game (dev|programmer)|fachinformatiker\w*|softwareentwicklung|entwickler\w*|informatik)\b/iu';

    private const string NOT_TECH = '/\b(sales|vente|r[eé]seaux sociaux|commercial|business develop\w*|marketing|social media|community manager|content creat\w*|copywrit\w*|journalis\w*|audit|accounting|comptab\w*|financ\w*|legal|juridique|human resources|ressources humaines|hr|recruit\w*|talent acquisition|customer service|kundenservice|assistant(e)? de direction|strategy|brand|supply|manufacturing|industrial|process engineer\w*|mechanical|civil|chemical|photonics|operations?|area manager|conversion|medical|clinical|laboratory|field)\b/iu';

    private const string INTERNSHIP = '/\b(intern(ship)?s?|stag(e|iaire)s?|praktik(um|ant\w*)|werkstudent\w*|working student|trainee|est[aá]gi(o|[aá]rio)|pr[aá]cticas|co-?op|placement|graduate program|academy)\b/iu';

    private const string APPRENTICESHIP = '/\b(alternan(ce|t|te)|apprenti(e|ssage)?|apprenticeship|ausbildung|contrat pro(fessionnalisation)?|duales? studium)\b/iu';

    /**
     * @param list<string> $tags
     */
    public static function isTech(string $title, array $tags = []): bool
    {
        if (1 === preg_match(self::NOT_TECH, $title)) {
            return false;
        }

        return 1 === preg_match(self::TECH, $title.' '.implode(' ', $tags));
    }

    /**
     * Internship or apprenticeship read from the title and tags; null when neither applies.
     *
     * @param list<string> $tags
     */
    public static function contractType(string $title, array $tags = []): ?ContractType
    {
        $haystack = $title.' '.implode(' ', $tags);

        return match (true) {
            1 === preg_match(self::APPRENTICESHIP, $haystack) => ContractType::Apprenticeship,
            1 === preg_match(self::INTERNSHIP, $haystack) => ContractType::Internship,
            default => null,
        };
    }
}
