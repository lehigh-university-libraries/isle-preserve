package helper

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"strconv"
	"strings"
	"time"
)

const (
	// DefaultPowDifficulty is the default number of leading zeros required in the hash
	DefaultPowDifficulty = 4
)

// GeneratePowChallenge generates a random challenge string for proof-of-work
func GeneratePowChallenge() string {
	// Use timestamp + random bytes for uniqueness
	timestamp := time.Now().UnixNano()
	randomBytes := make([]byte, 16)
	_, _ = rand.Read(randomBytes)
	return fmt.Sprintf("%d-%s", timestamp, hex.EncodeToString(randomBytes))
}

// VerifyPowSolution verifies that a nonce produces a valid proof-of-work for the given challenge
func VerifyPowSolution(challenge string, nonce int, difficulty int) bool {
	// Compute SHA-256(challenge + nonce)
	input := challenge + strconv.Itoa(nonce)
	hash := sha256.Sum256([]byte(input))
	hashHex := hex.EncodeToString(hash[:])

	// Check if hash has required number of leading zeros
	target := strings.Repeat("0", difficulty)
	return strings.HasPrefix(hashHex, target)
}

// GetPowJS returns the proof-of-work JavaScript implementation
func GetPowJS() string {
	return `// Proof of Work CAPTCHA
(function() {
    function initPoW() {
        var captchaDiv = document.querySelector('[data-callback]');
        if (!captchaDiv) {
            console.error('PoW: captcha div not found');
            return;
        }

        var callbackName = captchaDiv.getAttribute('data-callback');
        var challenge = captchaDiv.getAttribute('data-challenge');
        var difficulty = parseInt(captchaDiv.getAttribute('data-difficulty') || '4', 10);

        if (!callbackName || !challenge) {
            console.error('PoW: missing callback or challenge');
            return;
        }

        var progressDiv = document.createElement('div');
        progressDiv.id = 'pow-progress';
        progressDiv.style.marginTop = '20px';
        progressDiv.style.fontFamily = 'monospace';
        progressDiv.textContent = 'Computing proof of work...';
        captchaDiv.parentNode.insertBefore(progressDiv, captchaDiv.nextSibling);

        // Create worker from function
        var worker = createWorker(powWorker);

        worker.onmessage = function(e) {
            if (e.data.nonce !== undefined) {
                progressDiv.textContent = 'Proof of work completed in ' + (e.data.duration / 1000).toFixed(2) + 's (tried ' + e.data.nonce + ' nonces)';
                var token = challenge + ':' + e.data.nonce;
                if (typeof window[callbackName] === 'function') {
                    window[callbackName](token);
                }
                worker.terminate();
            } else if (e.data.progress !== undefined) {
                progressDiv.textContent = 'Computing proof of work... (' + e.data.progress + ' attempts, ' + (e.data.duration / 1000).toFixed(1) + 's)';
            }
        };

        worker.onerror = function(error) {
            console.error('PoW worker error:', error);
            progressDiv.textContent = 'Error computing proof of work';
        };

        worker.postMessage({
            challenge: challenge,
            difficulty: difficulty
        });
    }

    // Helper to create worker from function
    function createWorker(fn) {
        var blob = new Blob(['(' + fn.toString() + ')()'], { type: 'application/javascript' });
        return new Worker(URL.createObjectURL(blob));
    }

    // Worker function (will be serialized and run in worker context)
    function powWorker() {
        self.onmessage = function(e) {
            var challenge = e.data.challenge;
            var difficulty = e.data.difficulty;
            var target = "0".repeat(difficulty);
            var nonce = 0;
            var startTime = Date.now();
            
            while (true) {
                var hash = sha256(challenge + nonce);
                if (hash.substring(0, difficulty) === target) {
                    self.postMessage({ nonce: nonce, hash: hash, duration: Date.now() - startTime });
                    return;
                }
                nonce++;
                if (nonce % 10000 === 0) {
                    self.postMessage({ progress: nonce, duration: Date.now() - startTime });
                }
            }
        };

        function sha256(ascii) {
            function rightRotate(value, amount) { return (value >>> amount) | (value << (32 - amount)); }
            var mathPow = Math.pow, maxWord = mathPow(2, 32), lengthProperty = "length", i, j, result = "";
            var words = [], asciiBitLength = ascii[lengthProperty] * 8;
            var hash = sha256.h = sha256.h || [], k = sha256.k = sha256.k || [], primeCounter = k[lengthProperty];
            var isComposite = {};
            for (var candidate = 2; primeCounter < 64; candidate++) {
                if (!isComposite[candidate]) {
                    for (i = 0; i < 313; i += candidate) { isComposite[i] = candidate; }
                    hash[primeCounter] = (mathPow(candidate, .5) * maxWord) | 0;
                    k[primeCounter++] = (mathPow(candidate, 1 / 3) * maxWord) | 0;
                }
            }
            ascii += "\x80";
            while (ascii[lengthProperty] % 64 - 56) ascii += "\x00";
            for (i = 0; i < ascii[lengthProperty]; i++) {
                j = ascii.charCodeAt(i);
                if (j >> 8) return;
                words[i >> 2] |= j << ((3 - i) % 4) * 8;
            }
            words[words[lengthProperty]] = ((asciiBitLength / maxWord) | 0);
            words[words[lengthProperty]] = (asciiBitLength);
            for (j = 0; j < words[lengthProperty];) {
                var w = words.slice(j, j += 16), oldHash = hash;
                hash = hash.slice(0, 8);
                for (i = 0; i < 64; i++) {
                    var w15 = w[i - 15], w2 = w[i - 2], a = hash[0], e = hash[4];
                    var temp1 = hash[7] + (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25)) + ((e & hash[5]) ^ ((~e) & hash[6])) + k[i] + (w[i] = (i < 16) ? w[i] : (w[i - 16] + (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3)) + w[i - 7] + (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10))) | 0);
                    var temp2 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22)) + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));
                    hash = [(temp1 + temp2) | 0].concat(hash);
                    hash[4] = (hash[4] + temp1) | 0;
                }
                for (i = 0; i < 8; i++) { hash[i] = (hash[i] + oldHash[i]) | 0; }
            }
            for (i = 0; i < 8; i++) {
                for (j = 3; j + 1; j--) {
                    var b = (hash[i] >> (j * 8)) & 255;
                    result += ((b < 16) ? 0 : "") + b.toString(16);
                }
            }
            return result;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPoW);
    } else {
        initPoW();
    }
})();`
}
