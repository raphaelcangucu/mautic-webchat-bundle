module.exports = {
  apps: [{
    name: "mautic-webchat-realtime",
    script: "server.mjs",
    cwd: __dirname,
    instances: 1,
    exec_mode: "fork",
    autorestart: true,
    max_memory_restart: "192M",
    env: {
      NODE_ENV: "production",
      WEBCHAT_HOST: "127.0.0.1",
      WEBCHAT_PORT: "8790"
    }
  }]
};
