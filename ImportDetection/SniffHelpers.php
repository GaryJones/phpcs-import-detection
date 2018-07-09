<?php
declare(strict_types=1);

namespace ImportDetection;

use ImportDetection\Symbol;
use PHP_CodeSniffer\Files\File;

class SniffHelpers {
	public function isObjectReference(File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();
		$prevPtr = $phpcsFile->findPrevious([T_OBJECT_OPERATOR], $stackPtr - 1, $stackPtr - 2);
		return ($prevPtr && isset($tokens[$prevPtr]));
	}

	public function isStaticReference(File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();
		$prevPtr = $phpcsFile->findPrevious([T_DOUBLE_COLON], $stackPtr - 1, $stackPtr - 2);
		return ($prevPtr && isset($tokens[$prevPtr]));
	}

	// Borrowed this idea from
	// https://pear.php.net/reference/PHP_CodeSniffer-3.1.1/apidoc/PHP_CodeSniffer/LowercasePHPFunctionsSniff.html
	public function isBuiltInFunction(File $phpcsFile, $stackPtr) {
		$allFunctions = get_defined_functions();
		$builtInFunctions = array_flip($allFunctions['internal']);
		$tokens = $phpcsFile->getTokens();
		$functionName = $tokens[$stackPtr]['content'];
		return isset($builtInFunctions[strtolower($functionName)]);
	}

	public function isPredefinedTypehint(File $phpcsFile, $stackPtr) {
		$allTypehints = [
			'bool',
			'string',
			'int',
			'float',
			'void',
			'self',
			'array',
			'callable',
			'iterable',
		];
		$tokens = $phpcsFile->getTokens();
		$tokenContent = $tokens[$stackPtr]['content'];
		return in_array($tokenContent, $allTypehints, true);
	}

	public function isPredefinedConstant(File $phpcsFile, $stackPtr) {
		$allConstants = get_defined_constants();
		$tokens = $phpcsFile->getTokens();
		$constantName = $tokens[$stackPtr]['content'];
		return isset($allConstants[$constantName]);
	}

	public function isPredefinedClass(File $phpcsFile, $stackPtr) {
		$allClasses = get_declared_classes();
		$tokens = $phpcsFile->getTokens();
		$className = $tokens[$stackPtr]['content'];
		return in_array($className, $allClasses);
	}

	public function getImportType(File $phpcsFile, $stackPtr): string {
		$tokens = $phpcsFile->getTokens();
		$nextStringPtr = $phpcsFile->findNext([T_STRING], $stackPtr + 1);
		if (! $nextStringPtr) {
			return 'unknown';
		}
		$isClosureImport = $phpcsFile->findNext([T_OPEN_PARENTHESIS], $stackPtr + 1, $nextStringPtr);
		if ($isClosureImport) {
			return 'closure';
		}
		$nextString = $tokens[$nextStringPtr];
		if ($nextString['content'] === 'function') {
			return 'function';
		}
		if ($nextString['content'] === 'const') {
			return 'const';
		}
		return 'class';
	}

	private function getImportNamesFromGroup(File $phpcsFile, int $stackPtr): array {
		$tokens = $phpcsFile->getTokens();
		$endBracketPtr = $phpcsFile->findNext([T_CLOSE_USE_GROUP], $stackPtr + 1);
		if (! $endBracketPtr) {
			return [];
		}
		$lastImportPtr = $stackPtr;
		$collectedSymbols = [];
		$isLastImport = false;
		while (! $isLastImport) {
			$nextEndOfImportPtr = $phpcsFile->findNext([T_COMMA], $lastImportPtr + 1, $endBracketPtr);
			if (! $nextEndOfImportPtr) {
				$isLastImport = true;
				$nextEndOfImportPtr = $endBracketPtr;
			}
			$lastStringPtr = $phpcsFile->findPrevious([T_STRING], $nextEndOfImportPtr - 1, $stackPtr);
			if (! $lastStringPtr || ! isset($tokens[$lastStringPtr])) {
				break;
			}
			$collectedSymbols[] = $tokens[$lastStringPtr]['content'];
			$lastImportPtr = $nextEndOfImportPtr;
		}
		return $collectedSymbols;
	}

	public function getImportNames(File $phpcsFile, $stackPtr): array {
		$tokens = $phpcsFile->getTokens();

		$endOfStatementPtr = $phpcsFile->findNext([T_SEMICOLON], $stackPtr + 1);
		if (! $endOfStatementPtr) {
			return [];
		}

		// Process grouped imports differently
		$nextBracketPtr = $phpcsFile->findNext([T_OPEN_USE_GROUP], $stackPtr + 1, $endOfStatementPtr);
		if ($nextBracketPtr) {
			return $this->getImportNamesFromGroup($phpcsFile, $nextBracketPtr);
		}

		// Get the last string before the last semicolon, comma, or closing curly bracket
		$endOfImportPtr = $phpcsFile->findPrevious(
			[T_COMMA, T_CLOSE_USE_GROUP],
			$stackPtr + 1,
			$endOfStatementPtr
		);
		if (! $endOfImportPtr) {
			$endOfImportPtr = $endOfStatementPtr;
		}
		$lastStringPtr = $phpcsFile->findPrevious([T_STRING], $endOfImportPtr - 1, $stackPtr);
		if (! $lastStringPtr || ! isset($tokens[$lastStringPtr])) {
			return [];
		}
		return [$tokens[$lastStringPtr]['content']];
	}

	public function getPreviousStatementPtr(File $phpcsFile, int $stackPtr): int {
		return $phpcsFile->findPrevious([T_SEMICOLON, T_CLOSE_CURLY_BRACKET], $stackPtr - 1) ?: 1;
	}

	public function isWithinDeclareCall(File $phpcsFile, $stackPtr): bool {
		$previousStatementPtr = $this->getPreviousStatementPtr($phpcsFile, $stackPtr);
		return !! $phpcsFile->findPrevious([T_DECLARE], $stackPtr - 1, $previousStatementPtr);
	}

	public function isWithinDefineCall(File $phpcsFile, $stackPtr): bool {
		$previousStatementPtr = $this->getPreviousStatementPtr($phpcsFile, $stackPtr);
		return !! $phpcsFile->findPrevious([T_STRING], $stackPtr - 1, $previousStatementPtr, false, 'define');
	}

	public function isWithinNamespaceStatement(File $phpcsFile, $stackPtr): bool {
		$previousStatementPtr = $this->getPreviousStatementPtr($phpcsFile, $stackPtr);
		return !! $phpcsFile->findPrevious([T_NAMESPACE], $stackPtr - 1, $previousStatementPtr);
	}

	public function isWithinUseStatement(File $phpcsFile, $stackPtr): bool {
		$isClosureImport = $phpcsFile->findNext([T_OPEN_PARENTHESIS], $stackPtr + 1, $stackPtr + 5);
		if ($isClosureImport) {
			return false;
		}
		$previousStatementPtr = $this->getPreviousStatementPtr($phpcsFile, $stackPtr);
		return !! $phpcsFile->findPrevious([T_USE], $stackPtr - 1, $previousStatementPtr);
	}

	/**
	 * @return array|null
	 */
	public function getPreviousNonWhitespaceToken(File $phpcsFile, int $stackPtr) {
		$tokens = $phpcsFile->getTokens();
		$prevNonWhitespacePtr = $phpcsFile->findPrevious(T_WHITESPACE, $stackPtr - 1, $stackPtr - 3, true, null, false);
		if (! $prevNonWhitespacePtr || ! isset($tokens[$prevNonWhitespacePtr])) {
			return null;
		}
		return $tokens[$prevNonWhitespacePtr];
	}

	public function getConstantName(File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();
		$nextStringPtr = $phpcsFile->findNext([T_STRING], $stackPtr + 1, $stackPtr + 3);
		if (! $nextStringPtr || ! isset($tokens[$nextStringPtr])) {
			return null;
		}
		return $tokens[$nextStringPtr]['content'];
	}

	public function isFunctionAMethod(File $phpcsFile, $stackPtr): bool {
		$tokens = $phpcsFile->getTokens();
		$currentToken = $tokens[$stackPtr];
		return ! empty($currentToken['conditions']);
	}

	public function isSymbolADefinition(File $phpcsFile, Symbol $symbol): bool {
		// if the previous non-whitespace token is const, function, class, or trait, it is a definition
		// Note: this does not handle use statements, for that use isWithinUseStatement
		$stackPtr = $symbol->getSymbolPosition();
		$prevToken = $this->getPreviousNonWhitespaceToken($phpcsFile, $stackPtr) ?? [];
		return $this->isTokenADefinition($prevToken) || $this->isWithinDefineCall($phpcsFile, $stackPtr) || $this->isWithinDeclareCall($phpcsFile, $stackPtr);
	}

	public function isTokenADefinition(array $token): bool {
		// Note: this does not handle use or define
		$type = $token['type'] ?? '';
		$definitionTypes = ['T_CLASS', 'T_FUNCTION', 'T_CONST'];
		return in_array($type, $definitionTypes, true);
	}

	public function getFullSymbol($phpcsFile, $stackPtr): Symbol {
		$originalPtr = $stackPtr;
		$tokens = $phpcsFile->getTokens();
		// go backwards and forward and collect all the tokens until we encounter
		// anything other than a backslash or a string
		$currentToken = Symbol::getTokenWithPosition($tokens[$stackPtr], $stackPtr);
		$fullSymbolParts = [];
		while ($this->isTokenASymbolPart($currentToken)) {
			$fullSymbolParts[] = $currentToken;
			$stackPtr--;
			$currentToken = Symbol::getTokenWithPosition($tokens[$stackPtr] ?? [], $stackPtr);
		}
		$fullSymbolParts = array_reverse($fullSymbolParts);
		$stackPtr = $originalPtr + 1;
		$currentToken = Symbol::getTokenWithPosition($tokens[$stackPtr] ?? [], $stackPtr);
		while ($this->isTokenASymbolPart($currentToken)) {
			$fullSymbolParts[] = $currentToken;
			$stackPtr++;
			$currentToken = Symbol::getTokenWithPosition($tokens[$stackPtr] ?? [], $stackPtr);
		}
		return new Symbol($fullSymbolParts);
	}

	public function isTokenASymbolPart(array $token): bool {
		$type = $token['type'] ?? '';
		$symbolParts = ['T_NS_SEPARATOR', 'T_STRING', 'T_RETURN_TYPE'];
		return in_array($type, $symbolParts, true);
	}
}
