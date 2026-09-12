module.exports = {
  preset: 'ts-jest',
  testEnvironment: 'node',
  testMatch: ['**/__tests__/**/*.test.ts'],
  setupFiles: ['<rootDir>/jest.setup-env.js'],
  setupFilesAfterEnv: [],
  testTimeout: 15000,
  maxWorkers: 1,
};
