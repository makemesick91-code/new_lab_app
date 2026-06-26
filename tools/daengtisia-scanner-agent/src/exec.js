'use strict';

/**
 * Safe command execution helper.
 *
 * SECURITY: callers pass a FIXED command and an array of arguments. We never
 * build a shell string from request input, and we never run with shell:true,
 * so request bodies can never inject arbitrary commands. Numeric scan options
 * (dpi/quality/width) are validated/clamped before they ever reach here.
 */

const { spawn } = require('child_process');

/**
 * Run a command, collecting stdout as a Buffer.
 *
 * @param {string} command
 * @param {string[]} args
 * @param {object} opts
 * @param {number} opts.timeoutMs
 * @returns {Promise<{stdout: Buffer, stderr: string}>}
 */
function runCommand(command, args, { timeoutMs = 30000 } = {}) {
  return new Promise((resolve, reject) => {
    let child;
    try {
      child = spawn(command, args, { shell: false });
    } catch (err) {
      reject(err);
      return;
    }

    const stdoutChunks = [];
    let stderr = '';
    let settled = false;

    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      child.kill('SIGKILL');
      const e = new Error(`Command timed out after ${timeoutMs}ms`);
      e.code = 'ETIMEDOUT';
      reject(e);
    }, timeoutMs);

    child.stdout.on('data', (d) => stdoutChunks.push(d));
    child.stderr.on('data', (d) => {
      // Cap stderr so a chatty driver cannot exhaust memory.
      if (stderr.length < 8192) stderr += d.toString();
    });

    child.on('error', (err) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      reject(err);
    });

    child.on('close', (code) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (code === 0) {
        resolve({ stdout: Buffer.concat(stdoutChunks), stderr });
      } else {
        const e = new Error(
          `Command "${command}" exited with code ${code}: ${stderr.trim()}`
        );
        e.code = 'ENONZERO';
        e.exitCode = code;
        reject(e);
      }
    });
  });
}

module.exports = { runCommand };
